<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Http\Requests\HR\StoreEmployeeRequest;
use App\Http\Requests\HR\UpdateEmployeeRequest;
use App\Models\Division;
use App\Models\Employee;
use App\Models\User;
use App\Services\DisciplineService;
use App\Services\EmployeeService;
use App\Services\KpiService;
use App\Services\PerformanceReviewService;
use App\Services\SalaryChangeService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SDM (Sprint 10) — the permanent-employee master, managed by HR, read by
 * the CEO (route `module:hr` + `role:HR` on writes). Finance only reads
 * employees on its Penggajian page and pays salaries there. No destroy:
 * employees are deactivated, never deleted. Every query goes through
 * `hrEligible()` so field staff never appear (decision #11).
 */
class EmployeeController extends Controller
{
    public function index(Request $request): Response
    {
        $divisionId = $request->integer('division') ?: null;
        $positionId = $request->integer('position') ?: null;
        $status = $request->string('status')->value();

        $employees = Employee::query()
            ->hrEligible()
            ->inStructure($divisionId, $positionId)
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%'.$request->string('search')->trim().'%'))
            ->with(['user:id,name', 'position:id,name,division_id', 'position.division:id,name'])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $canManage = $request->user()->hasAnyRole(['HR', 'SUPERADMIN']);

        return Inertia::render('HR/Employees/Index', [
            'employees' => $employees,
            'filters' => [
                'division' => $divisionId,
                'position' => $positionId,
                'status' => in_array($status, ['active', 'inactive'], true) ? $status : 'all',
                'search' => $request->string('search')->value(),
            ],
            'structure' => $this->structure(),
            'canManage' => $canManage,
            'linkableUsers' => $canManage ? $this->linkableUsers() : [],
        ]);
    }

    /**
     * Employee profile — Ringkasan · Kedisiplinan · Gaji · KPI · Evaluasi.
     * Each tab's data comes from its own service (`forEmployee()`), the
     * same methods the employee's own "Milik Saya" page uses.
     */
    public function show(
        Request $request,
        Employee $employee,
        DisciplineService $discipline,
        SalaryChangeService $salary,
        KpiService $kpi,
        PerformanceReviewService $reviews,
    ): Response {
        abort_unless(Employee::query()->hrEligible()->whereKey($employee->id)->exists(), 404);

        $employee->load(['user:id,name,email', 'position:id,name,division_id', 'position.division:id,name', 'creator:id,name']);
        $canManage = $request->user()->hasAnyRole(['HR', 'SUPERADMIN']);

        return Inertia::render('HR/Employees/Show', [
            'employee' => $employee,
            'discipline' => $discipline->forEmployee($employee),
            'salary' => $salary->forEmployee($employee),
            'kpi' => $kpi->forEmployee($employee),
            'reviews' => $reviews->forEmployee($employee),
            'canManage' => $canManage,
            'canDecideSalary' => $request->user()->hasAnyRole(['CEO', 'SUPERADMIN']),
            'structure' => $canManage ? $this->structure() : [],
            'linkableUsers' => $canManage ? $this->linkableUsers($employee) : [],
        ]);
    }

    public function store(StoreEmployeeRequest $request, EmployeeService $service): RedirectResponse
    {
        $employee = $service->create($request->validated(), $request->user());

        return redirect()->route('hr.employees.show', $employee)->with('success', "Karyawan {$employee->name} berhasil ditambahkan.");
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee, EmployeeService $service): RedirectResponse
    {
        $employee = $service->update($employee, $request->validated(), $request->user());

        return back()->with('success', "Data karyawan {$employee->name} berhasil diperbarui.");
    }

    /**
     * Divisions with their positions, for the position picker and the
     * division/position filters. Inactive ones are included (flagged) so an
     * employee still holding one shows it; the form only offers active ones.
     */
    private function structure(): Collection
    {
        return Division::query()
            ->ordered()
            ->with(['positions' => fn ($query) => $query->ordered()->select(['id', 'division_id', 'name', 'is_active'])])
            ->get(['id', 'name', 'is_active']);
    }

    /**
     * Accounts the "Akun Sistem" select may offer: active non-field-staff
     * users (decision #11), plus whoever is linked today so an existing
     * link still shows its name.
     */
    private function linkableUsers(?Employee $current = null): Collection
    {
        if ($current) {
            // Profile page: only accounts free to link, plus this employee's own.
            return User::query()
                ->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->withoutRole('FIELD_STAFF')
                    ->whereNotIn('id', Employee::query()->whereNotNull('user_id')->whereKeyNot($current->id)->select('user_id')))
                ->when($current->user_id, fn ($query) => $query->orWhere('id', $current->user_id))
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        return User::query()
            ->where(fn ($query) => $query->where('is_active', true)->withoutRole('FIELD_STAFF'))
            ->orWhereIn('id', Employee::query()->whereNotNull('user_id')->select('user_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
