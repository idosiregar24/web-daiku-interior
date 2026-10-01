<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PRD §4.7 "Pindah Dana" — mirrored by the Zod schema in
 * Components/modules/finance/FundTransferDialog.tsx. FundTransferService
 * re-checks the rules for callers that skip this request.
 */
class StoreFundTransferRequest extends FormRequest
{
    /** Route-level `role:FINANCE` middleware already gates this action (PRD §7.1 "Finance – Transaction" — Finance CRUD). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_bank_account_id' => ['required', 'integer', Rule::exists('bank_accounts', 'id')->where('is_active', true)],
            'to_bank_account_id' => ['required', 'integer', 'different:from_bank_account_id', Rule::exists('bank_accounts', 'id')->where('is_active', true)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return [
            'from_bank_account_id.required' => 'Rekening asal wajib dipilih.',
            'from_bank_account_id.integer' => 'Rekening asal tidak valid.',
            'from_bank_account_id.exists' => 'Rekening asal tidak valid atau tidak aktif.',
            'to_bank_account_id.required' => 'Rekening tujuan wajib dipilih.',
            'to_bank_account_id.integer' => 'Rekening tujuan tidak valid.',
            'to_bank_account_id.different' => 'Rekening tujuan harus berbeda dari rekening asal.',
            'to_bank_account_id.exists' => 'Rekening tujuan tidak valid atau tidak aktif.',
            'amount.required' => 'Nominal wajib diisi.',
            'amount.numeric' => 'Nominal harus berupa angka.',
            'amount.gt' => 'Nominal harus lebih dari 0.',
            'amount.max' => 'Nominal terlalu besar.',
            'date.required' => 'Tanggal wajib diisi.',
            'date.date_format' => 'Tanggal tidak valid.',
            'date.before_or_equal' => 'Tanggal pindah dana tidak boleh di masa depan.',
            'description.required' => 'Keterangan wajib diisi.',
            'description.max' => 'Keterangan maksimal 150 karakter.',
        ];
    }
}
