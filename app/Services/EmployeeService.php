<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * The permanent-employee master (PRD §4.7 "Gaji Karyawan Tetap"), managed
 * by HR since Sprint 10 (Finance reads it and pays salaries). Salaries are
 * confidential, so creating an employee and every change to their data
 * (position, deactivation, linked account, bank details) is audited
 * (PRD §9.4). Employees are never deleted — only deactivated.
 *
 * `base_salary` is set once here, on creation; afterwards it only changes
 * through SalaryChangeService (HR requests, CEO approves — decision #3).
 */
class EmployeeService
{
    private const CREATABLE = ['name', 'position_id', 'user_id', 'base_salary', 'bank_name', 'account_no', 'join_date', 'notes'];

    private const EDITABLE = ['name', 'position_id', 'user_id', 'bank_name', 'account_no', 'join_date', 'notes', 'is_active'];

    private const AUDITED = ['name', 'position_id', 'user_id', 'base_salary', 'bank_name', 'account_no', 'join_date', 'is_active'];

    public function __construct(private AuditLogService $auditLogService) {}

    public function create(array $data, User $actor): Employee
    {
        return DB::transaction(function () use ($data, $actor) {
            $employee = Employee::create([
                ...Arr::only($data, self::CREATABLE),
                'is_active' => true,
                'created_by' => $actor->id,
            ])->refresh();

            $this->auditLogService->record('hr.employee_created', $employee, null, $employee->only(self::AUDITED), $actor);

            return $employee;
        });
    }

    public function update(Employee $employee, array $data, User $actor): Employee
    {
        return DB::transaction(function () use ($employee, $data, $actor) {
            $locked = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $locked->fill(Arr::only($data, self::EDITABLE));

            $changed = array_values(array_intersect(self::AUDITED, array_keys($locked->getDirty())));
            $old = Arr::only($locked->getOriginal(), $changed);

            $locked->save();

            if ($changed !== []) {
                $this->auditLogService->record('hr.employee_updated', $locked, $old, $locked->only($changed), $actor);
            }

            return $locked;
        });
    }
}
