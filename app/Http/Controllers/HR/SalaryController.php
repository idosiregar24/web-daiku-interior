<?php

namespace App\Http\Controllers\HR;

use App\Enums\SalaryChangeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\HR\RejectSalaryChangeRequest;
use App\Http\Requests\HR\StoreSalaryChangeRequest;
use App\Models\Division;
use App\Models\Employee;
use App\Models\PerformanceReview;
use App\Models\SalaryChange;
use App\Services\SalaryChangeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SDM (Sprint 10, decision #3, §3.2) — Gaji: base-salary changes (HR
 * requests, the CEO approves/rejects) and the salary-cost recap per month
 * and per division/position. Read by CEO and HR (route `module:hr`);
 * Finance keeps paying on its own Penggajian page. No edit/delete route.
 */
class SalaryController extends Controller
{
    public function index(Request $request, SalaryChangeService $service): Response
    {
        $status = SalaryChangeStatus::tryFrom($request->string('status')->value());
        $divisionId = $request->integer('division') ?: null;
        $positionId = $request->integer('position') ?: null;
        $canManage = $request->user()->hasAnyRole(['HR', 'SUPERADMIN']);

        $changes = SalaryChange::query()
            ->whereHas('employee', fn ($query) => $query->hrEligible()->inStructure($divisionId, $positionId))
            ->when($status, fn ($query) => $query->where('status', $status->value))
            ->with([
                'employee:id,name,position_id',
                'employee.position:id,name,division_id',
                'employee.position.division:id,name',
                'requester:id,name',
                'decider:id,name',
            ])
            // Waiting for the CEO first, then the newest history.
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [SalaryChangeStatus::Pending->value])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (SalaryChange $change) => $service->present($change));

        $months = $service->monthlyTotals(12);
        $month = $this->month($request->string('month')->value(), $months);

        return Inertia::render('HR/Salary/Index', [
            'changes' => $changes,
            'filters' => [
                'status' => $status?->value,
                'division' => $divisionId,
                'position' => $positionId,
                'month' => $month,
                'tab' => $request->string('tab')->value() === 'recap' ? 'recap' : 'changes',
            ],
            'summary' => $service->dashboardSummary(),
            'recap' => [
                'months' => $months,
                'breakdown' => $service->periodBreakdown($month),
            ],
            'structure' => Division::query()
                ->ordered()
                ->with(['positions' => fn ($query) => $query->ordered()->select(['id', 'division_id', 'name', 'is_active'])])
                ->get(['id', 'name', 'is_active']),
            'canManage' => $canManage,
            'canDecide' => $request->user()->hasAnyRole(['CEO', 'SUPERADMIN']),
            'employees' => $canManage ? $this->requestableEmployees() : [],
            'prefill' => $canManage ? $this->prefill($request) : null,
        ]);
    }

    public function store(StoreSalaryChangeRequest $request, SalaryChangeService $service): RedirectResponse
    {
        $employee = Employee::findOrFail($request->integer('employee_id'));
        $service->request($employee, $request->validated(), $request->user());

        return redirect()->route('hr.salary.index')->with('success', "Pengajuan perubahan gaji {$employee->name} dikirim ke CEO.");
    }

    public function approve(Request $request, SalaryChange $salaryChange, SalaryChangeService $service): RedirectResponse
    {
        $change = $service->approve($salaryChange, $request->user());

        return back()->with('success', $change->applied_at
            ? "Perubahan gaji {$change->employee->name} disetujui dan gaji pokok sudah diperbarui."
            : "Perubahan gaji {$change->employee->name} disetujui — gaji pokok berubah pada {$change->effective_date->translatedFormat('d F Y')}.");
    }

    public function reject(RejectSalaryChangeRequest $request, SalaryChange $salaryChange, SalaryChangeService $service): RedirectResponse
    {
        $change = $service->reject($salaryChange, $request->validated('reject_note'), $request->user());

        return back()->with('success', "Perubahan gaji {$change->employee->name} ditolak.");
    }

    /**
     * Active HR-eligible employees for the "Ajukan" dialog, flagged when a
     * request is already waiting (pending, or approved but not yet effective).
     *
     * @return array<int, array<string, mixed>>
     */
    private function requestableEmployees(): array
    {
        $blocked = SalaryChange::query()
            ->where(fn ($query) => $query
                ->where('status', SalaryChangeStatus::Pending->value)
                ->orWhere(fn ($approved) => $approved->where('status', SalaryChangeStatus::Approved->value)->whereNull('applied_at')))
            ->pluck('employee_id')
            ->flip();

        return Employee::query()
            ->hrEligible()
            ->where('is_active', true)
            ->with(['position:id,name,division_id', 'position.division:id,name'])
            ->orderBy('name')
            ->get(['id', 'name', 'position_id', 'base_salary'])
            ->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'name' => $employee->name,
                'base_salary' => $employee->base_salary,
                'position' => $employee->position?->name,
                'division' => $employee->position?->division?->name,
                'has_open_request' => $blocked->has($employee->id),
            ])
            ->all();
    }

    /**
     * `?request_for={employee}&review={performance_review}` — the review
     * page's "naik gaji" shortcut opens the request dialog prefilled. The
     * review is kept only when it belongs to that employee.
     *
     * @return array{employee_id: int, performance_review_id: int|null}|null
     */
    private function prefill(Request $request): ?array
    {
        $employeeId = $request->integer('request_for');

        if (! $employeeId || ! Employee::query()->hrEligible()->where('is_active', true)->whereKey($employeeId)->exists()) {
            return null;
        }

        $reviewId = $request->integer('review') ?: null;

        if ($reviewId && ! PerformanceReview::query()->whereKey($reviewId)->where('employee_id', $employeeId)->exists()) {
            $reviewId = null;
        }

        return ['employee_id' => $employeeId, 'performance_review_id' => $reviewId];
    }

    /**
     * `YYYY-MM` for the per-division/position breakdown — must be one of the
     * recap months; defaults to the latest month with payments, else the
     * current month.
     *
     * @param  array<int, array{period: string, total: float}>  $months
     */
    private function month(string $requested, array $months): string
    {
        $periods = array_column($months, 'period');

        if (in_array($requested, $periods, true)) {
            return $requested;
        }

        $paid = array_values(array_filter($months, fn (array $month) => $month['total'] > 0));

        return $paid ? end($paid)['period'] : end($periods);
    }
}
