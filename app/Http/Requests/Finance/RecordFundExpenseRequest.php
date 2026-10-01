<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Catat Penggunaan Dana" (PRD §4.7) — mirrored by the Zod schema in
 * Components/modules/finance/FundExpenseDialog.tsx. The "≤ saldo yang bisa
 * dipakai" rule lives in FamilyGatheringFundService::recordExpense() — it
 * needs the ledger lock.
 */
class RecordFundExpenseRequest extends FormRequest
{
    /** Route-level `role:FINANCE` middleware already gates this action (PRD §7.1 "Finance – Family Fund" row — FIN has CRUD). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'description' => ['required', 'string', 'max:255'],
            // Sprint 9 decision #10 — the usage leaves this account as a PENGELUARAN (PRD §4.7).
            'bank_account_id' => ['required', 'integer', Rule::exists('bank_accounts', 'id')->where('is_active', true)],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Nominal wajib diisi.',
            'amount.numeric' => 'Nominal harus berupa angka.',
            'amount.gt' => 'Nominal harus lebih dari 0.',
            'amount.max' => 'Nominal terlalu besar.',
            'description.required' => 'Keterangan penggunaan dana wajib diisi.',
            'description.max' => 'Keterangan maksimal 255 karakter.',
            'bank_account_id.required' => 'Rekening sumber dana wajib dipilih.',
            'bank_account_id.integer' => 'Rekening tidak valid.',
            'bank_account_id.exists' => 'Rekening bank tidak ditemukan atau tidak aktif.',
            'date.required' => 'Tanggal wajib diisi.',
            'date.date_format' => 'Tanggal tidak valid.',
            'date.before_or_equal' => 'Tanggal penggunaan dana tidak boleh di masa depan.',
        ];
    }
}
