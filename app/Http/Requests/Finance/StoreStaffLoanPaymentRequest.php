<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffLoanPaymentRequest extends FormRequest
{
    /**
     * Route-level `role:FINANCE` middleware already gates this action. The
     * "must not exceed the remaining balance" rule lives in
     * StaffLoanService::recordPayment() — it needs the row lock.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'paid_date' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
            // The repayment is booked as PINJAMAN income on this account (PRD §4.7).
            'bank_account_id' => ['required', 'integer', Rule::exists('bank_accounts', 'id')->where('is_active', true)],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Nominal pembayaran wajib diisi.',
            'amount.numeric' => 'Nominal pembayaran harus berupa angka.',
            'amount.gt' => 'Nominal pembayaran harus lebih dari 0.',
            'paid_date.required' => 'Tanggal bayar wajib diisi.',
            'paid_date.date' => 'Tanggal bayar tidak valid.',
            'paid_date.before_or_equal' => 'Tanggal bayar tidak boleh di masa depan.',
            'note.max' => 'Catatan maksimal 255 karakter.',
            'bank_account_id.required' => 'Rekening penerima wajib dipilih.',
            'bank_account_id.exists' => 'Rekening bank tidak ditemukan atau tidak aktif.',
        ];
    }
}
