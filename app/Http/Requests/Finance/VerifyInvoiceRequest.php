<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class VerifyInvoiceRequest extends FormRequest
{
    /** Route-level `role:FINANCE` gates this (Sprint 12 #21). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bank_account_id' => ['required', 'integer', 'exists:bank_accounts,id'],
            'paid_date' => ['required', 'date', 'before_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'bank_account_id.required' => 'Rekening penerima wajib dipilih.',
            'paid_date.required' => 'Tanggal pembayaran wajib diisi.',
            'paid_date.before_or_equal' => 'Tanggal pembayaran tidak boleh di masa depan.',
        ];
    }
}
