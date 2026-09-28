<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayStaffRequest extends FormRequest
{
    /** Route-level `role:FINANCE` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // PRD §4.7 "Setiap transaksi wajib mencantumkan rekening bank".
            'bank_account_id' => ['required', 'integer', Rule::exists('bank_accounts', 'id')->where('is_active', true)],
        ];
    }

    public function messages(): array
    {
        return [
            'bank_account_id.required' => 'Rekening sumber wajib dipilih.',
            'bank_account_id.exists' => 'Rekening bank tidak ditemukan atau tidak aktif.',
        ];
    }
}
