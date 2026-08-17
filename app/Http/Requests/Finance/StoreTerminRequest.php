<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class StoreTerminRequest extends FormRequest
{
    /** Route-level `role:PM` middleware already gates this action (PRD §7.1 "Finance – Termin" — PM has `C`). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'milestone_id' => ['nullable', 'exists:milestones,id'],
            'percentage' => ['required', 'integer', 'min:1', 'max:100'],
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'percentage.required' => 'Persentase termin wajib diisi.',
            'percentage.max' => 'Persentase maksimal 100.',
        ];
    }
}
