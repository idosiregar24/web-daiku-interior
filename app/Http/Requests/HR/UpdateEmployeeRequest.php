<?php

namespace App\Http\Requests\HR;

use Illuminate\Support\Arr;

/**
 * Same rules as creating, plus deactivation — employees are never deleted.
 * The base salary is NOT editable here (Sprint 10 decision #3): it only
 * changes through a salary-change request the CEO approves.
 */
class UpdateEmployeeRequest extends StoreEmployeeRequest
{
    public function rules(): array
    {
        return [
            ...Arr::except(parent::rules(), ['base_salary']),
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'is_active.required' => 'Status aktif wajib diisi.',
            'is_active.boolean' => 'Status aktif tidak valid.',
        ];
    }
}
