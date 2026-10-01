<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * PRD §4.7 "Gaji Karyawan Tetap" — the employee master Finance keeps for
 * payroll. Salaries are confidential finance data, so creating an employee
 * and every change to their data (base salary, deactivation, linked
 * account, bank details) is audited (PRD §9.4). Employees are never
 * deleted — only deactivated.
 */
class EmployeeService
{
    private const EDITABLE = ['name', 'position', 'user_id', 'base_salary', 'bank_name', 'account_no', 'join_date', 'notes'];

    private const AUDITED = ['name', 'position', 'user_id', 'base_salary', 'bank_name', 'account_no', 'join_date', 'is_active'];

    public function __construct(private AuditLogService $auditLogService) {}

    public function create(array $data, User $actor): Employee
    {
        return DB::transaction(function () use ($data, $actor) {
            $employee = Employee::create([
                ...Arr::only($data, self::EDITABLE),
                'is_active' => true,
                'created_by' => $actor->id,
            ])->refresh();

            $this->auditLogService->record('finance.employee_created', $employee, null, $employee->only(self::AUDITED), $actor);

            return $employee;
        });
    }

    public function update(Employee $employee, array $data, User $actor): Employee
    {
        return DB::transaction(function () use ($employee, $data, $actor) {
            $locked = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $locked->fill(Arr::only($data, [...self::EDITABLE, 'is_active']));

            $changed = array_values(array_intersect(self::AUDITED, array_keys($locked->getDirty())));
            $old = Arr::only($locked->getOriginal(), $changed);

            $locked->save();

            if ($changed !== []) {
                $this->auditLogService->record('finance.employee_updated', $locked, $old, $locked->only($changed), $actor);
            }

            return $locked;
        });
    }
}
