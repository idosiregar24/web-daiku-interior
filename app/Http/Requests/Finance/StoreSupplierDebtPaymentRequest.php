<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierDebtPaymentRequest extends FormRequest
{
    /** Route-level `role:FINANCE` middleware already gates this action (PRD §7.1 "Finance – Transaction"). */
    public function authorize(): bool
    {
        return true;
    }

    /** "Not more than the remaining debt" is checked in SupplierDebtService under a row lock. */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'paid_date' => ['required', 'date', 'before_or_equal:today'],
            // PRD §4.7 "Setiap transaksi wajib mencantumkan rekening bank".
            'bank_account_id' => ['required', Rule::exists('bank_accounts', 'id')->where('is_active', true)],
            'note' => ['nullable', 'string', 'max:255'],
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
            'bank_account_id.required' => 'Rekening bank wajib dipilih.',
            'bank_account_id.exists' => 'Rekening bank tidak ditemukan atau tidak aktif.',
            'note.max' => 'Catatan maksimal 255 karakter.',
        ];
    }
}
