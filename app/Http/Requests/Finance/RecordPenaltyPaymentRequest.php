<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Catat Pembayaran" penalti (Sprint 9 decision #10) — mirrored by the Zod
 * schema in Components/modules/finance/PenaltyPaymentDialog.tsx. Whether
 * each penalty exists, belongs to `staff_id` and is still unpaid is
 * checked by PenaltyCollectionService under a row lock (a check here
 * could pass for two requests at once).
 */
class RecordPenaltyPaymentRequest extends FormRequest
{
    /** Route-level `role:FINANCE` middleware already gates this action (PRD §7.1 "Finance – Family Fund"/"Transaction" — Finance CRUD). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'staff_id' => ['required', 'integer', 'exists:users,id'],
            'penalty_ids' => ['required', 'array', 'min:1', 'max:500'],
            'penalty_ids.*' => ['required', 'integer', 'distinct'],
            // The payment is booked as PEMASUKAN PENALTY_COLLECT on this account (PRD §4.7).
            'bank_account_id' => ['required', 'integer', Rule::exists('bank_accounts', 'id')->where('is_active', true)],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'staff_id.required' => 'Tukang wajib dipilih.',
            'staff_id.integer' => 'Tukang tidak valid.',
            'staff_id.exists' => 'Tukang tidak ditemukan.',
            'penalty_ids.required' => 'Pilih minimal satu penalti yang dibayar.',
            'penalty_ids.array' => 'Daftar penalti tidak valid.',
            'penalty_ids.min' => 'Pilih minimal satu penalti yang dibayar.',
            'penalty_ids.max' => 'Maksimal 500 penalti per pembayaran.',
            'penalty_ids.*.required' => 'Daftar penalti tidak valid.',
            'penalty_ids.*.integer' => 'Daftar penalti tidak valid.',
            'penalty_ids.*.distinct' => 'Penalti yang sama dipilih lebih dari sekali.',
            'bank_account_id.required' => 'Rekening penerima wajib dipilih.',
            'bank_account_id.integer' => 'Rekening penerima tidak valid.',
            'bank_account_id.exists' => 'Rekening bank tidak ditemukan atau tidak aktif.',
            'date.required' => 'Tanggal bayar wajib diisi.',
            'date.date_format' => 'Tanggal bayar tidak valid.',
            'date.before_or_equal' => 'Tanggal bayar tidak boleh di masa depan.',
            'note.string' => 'Catatan tidak valid.',
            'note.max' => 'Catatan maksimal 255 karakter.',
        ];
    }
}
