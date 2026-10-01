<?php

namespace App\Http\Requests\Finance;

/** Same rules as creating, plus deactivation — employees are never deleted. */
class UpdateEmployeeRequest extends StoreEmployeeRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
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
