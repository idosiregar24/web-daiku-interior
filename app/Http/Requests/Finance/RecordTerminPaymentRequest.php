<?php

namespace App\Http\Requests\Finance;

use App\Services\TerminService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PRD §4.7 "DP + pelunasan" — shape validation only. The rules that need
 * the locked termin row (amount <= sisa_piutang, no DP after pelunasan,
 * milestone gate) live in TerminService::recordPayment(). Mirrored by the
 * Zod schema in Pages/Finance/Termins/Index.tsx.
 */
class RecordTerminPaymentRequest extends FormRequest
{
    /** Route-level `role:FINANCE` middleware already gates this action (PRD §7.1 "Finance – Termin" — Finance `RU`). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([TerminService::PAYMENT_DP, TerminService::PAYMENT_PELUNASAN])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'bank_account_id' => ['required', Rule::exists('bank_accounts', 'id')->where('is_active', true)],
            'paid_date' => ['required', 'date', 'before_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Jenis pembayaran wajib dipilih.',
            'type.in' => 'Jenis pembayaran harus DP atau Pelunasan.',
            'amount.required' => 'Nominal pembayaran wajib diisi.',
            'amount.numeric' => 'Nominal pembayaran harus berupa angka.',
            'amount.gt' => 'Nominal pembayaran harus lebih dari 0.',
            'amount.max' => 'Nominal pembayaran terlalu besar.',
            'bank_account_id.required' => 'Rekening penerima wajib dipilih.',
            'bank_account_id.exists' => 'Rekening penerima tidak valid atau tidak aktif.',
            'paid_date.required' => 'Tanggal pembayaran wajib diisi.',
            'paid_date.date' => 'Tanggal pembayaran tidak valid.',
            'paid_date.before_or_equal' => 'Tanggal pembayaran tidak boleh di masa depan.',
        ];
    }
}
