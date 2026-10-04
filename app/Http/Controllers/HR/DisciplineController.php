<?php

namespace App\Http\Controllers\HR;

use App\Enums\DisciplinaryType;
use App\Exports\DisciplinaryRecordsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\HR\StoreDisciplinaryRecordRequest;
use App\Http\Requests\HR\VoidDisciplinaryRecordRequest;
use App\Models\DisciplinaryRecord;
use App\Models\Division;
use App\Models\Employee;
use App\Services\DisciplineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * SDM (Sprint 10, §3.1) — Kedisiplinan recap: reprimands and warning
 * letters. CEO reads (route `module:hr`), HR records and cancels
 * (`role:HR`). Append-only: there is no edit or delete route — a mistake
 * is cancelled with a PEMBATALAN entry (`void`).
 */
class DisciplineController extends Controller
{
    public function index(Request $request, DisciplineService $service): Response
    {
        $filters = $this->filters($request);

        $records = $service->recapQuery($filters)
            ->paginate(25)
            ->withQueryString()
            ->through(fn (DisciplinaryRecord $record) => $service->present($record));

        $canManage = $request->user()->hasAnyRole(['HR', 'SUPERADMIN']);
        $summary = $service->dashboardSummary();
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        $thisMonth = DisciplinaryRecord::query()
            ->effective()
            ->whereBetween('issued_on', [$monthStart, $monthEnd])
            ->whereHas('employee', fn ($query) => $query->hrEligible());

        return Inertia::render('HR/Discipline/Index', [
            'records' => $records,
            'filters' => $filters,
            'stats' => [
                'active_sp_count' => $summary['active_sp_count'],
                'employees_with_active_sp' => $summary['employees_with_active_sp'],
                'teguran_this_month' => (clone $thisMonth)->where('type', DisciplinaryType::TeguranLisan->value)->count(),
                'sp_this_month' => (clone $thisMonth)->whereIn('type', [DisciplinaryType::Sp1->value, DisciplinaryType::Sp2->value, DisciplinaryType::Sp3->value])->count(),
                'expiring_soon' => DisciplinaryRecord::query()
                    ->activeSp()
                    ->whereDate('valid_until', '<=', now()->addDays(30)->toDateString())
                    ->whereHas('employee', fn ($query) => $query->hrEligible()->where('is_active', true))
                    ->count(),
            ],
            'structure' => Division::query()
                ->ordered()
                ->with(['positions' => fn ($query) => $query->ordered()->select(['id', 'division_id', 'name', 'is_active'])])
                ->get(['id', 'name', 'is_active']),
            'canManage' => $canManage,
            'employees' => $canManage ? $this->issuableEmployees($service) : [],
        ]);
    }

    public function store(StoreDisciplinaryRecordRequest $request, DisciplineService $service): RedirectResponse
    {
        $employee = Employee::findOrFail($request->integer('employee_id'));
        $record = $service->issue($employee, $request->validated(), $request->user());

        return back()->with('success', "{$record->type->label()} untuk {$employee->name} berhasil dicatat.");
    }

    public function void(VoidDisciplinaryRecordRequest $request, DisciplinaryRecord $disciplinaryRecord, DisciplineService $service): RedirectResponse
    {
        $service->void($disciplinaryRecord, $request->validated('reason'), $request->user());

        return back()->with('success', "{$disciplinaryRecord->type->label()} berhasil dibatalkan.");
    }

    public function export(Request $request): BinaryFileResponse
    {
        return Excel::download(new DisciplinaryRecordsExport($this->filters($request)), 'kedisiplinan-'.now()->format('Y-m-d').'.xlsx');
    }

    /**
     * @return array{from: string|null, to: string|null, division: int|null, position: int|null, type: string|null, active_only: bool}
     */
    private function filters(Request $request): array
    {
        $date = fn (string $key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key)) === 1 ? (string) $request->query($key) : null;
        $type = DisciplinaryType::tryFrom($request->string('type')->value());

        return [
            'from' => $date('from'),
            'to' => $date('to'),
            'division' => $request->integer('division') ?: null,
            'position' => $request->integer('position') ?: null,
            'type' => $type?->value,
            'active_only' => $request->boolean('active_only'),
        ];
    }

    /**
     * Active HR-eligible employees for the "Catat" dialog, each with the SP
     * in force today and the only SP level allowed next (decision #12).
     *
     * @return array<int, array<string, mixed>>
     */
    private function issuableEmployees(DisciplineService $service): array
    {
        $employees = Employee::query()
            ->hrEligible()
            ->where('is_active', true)
            ->with(['position:id,name,division_id', 'position.division:id,name'])
            ->orderBy('name')
            ->get(['id', 'name', 'position_id']);

        $active = $service->activeSpByEmployee($employees->pluck('id')->all());

        return $employees->map(fn (Employee $employee) => [
            'id' => $employee->id,
            'name' => $employee->name,
            'position' => $employee->position?->name,
            'division' => $employee->position?->division?->name,
            'active_sp' => isset($active[$employee->id]) ? [
                'type' => $active[$employee->id]->type->value,
                'valid_until' => $active[$employee->id]->valid_until->toDateString(),
            ] : null,
            'next_sp' => DisciplineService::levelAfter($active[$employee->id]->type ?? null)?->value,
        ])->all();
    }
}
