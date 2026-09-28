<?php

namespace App\Http\Requests\Overtime;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OvertimeDecisionRequest extends FormRequest
{
    /** Route-level `role:PM` / `role:FINANCE` middleware already gates who reaches these actions. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'string', 'max:1000'],
            // Finance's approval writes the LEMBUR_BONUS expense — PRD §4.7
            // "Setiap transaksi wajib mencantumkan rekening bank".
            'bank_account_id' => [
                Rule::requiredIf(fn () => $this->routeIs('overtime.financeApprove')),
                'nullable',
                'integer',
                Rule::exists('bank_accounts', 'id')->where('is_active', true),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'decision.required' => 'Keputusan wajib dipilih.',
            'bank_account_id.required' => 'Rekening sumber wajib dipilih.',
            'bank_account_id.exists' => 'Rekening bank tidak ditemukan atau tidak aktif.',
        ];
    }
}
