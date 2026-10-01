<?php

namespace App\Http\Requests\Finance;

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinanceTransactionRequest extends FormRequest
{
    /** Route-level `role:FINANCE` middleware already gates this action (PRD §7.1 "Finance – Transaction" — Finance has CRUD). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['nullable', 'exists:projects,id'],
            // PRD §4.7 "Setiap transaksi wajib mencantumkan rekening bank" —
            // an active one, like every other flow that moves cash.
            'bank_account_id' => ['required', Rule::exists('bank_accounts', 'id')->where('is_active', true)],
            'type' => ['required', Rule::enum(FinanceTransactionType::class)],
            // FinanceCategory::systemManaged() — written only by their own
            // flows (Termin, Pinjaman Tukang, Hutang Supplier, Pindah Dana,
            // Upah Tukang/Penggajian).
            'kategori' => ['required', Rule::enum(FinanceCategory::class)->except(FinanceCategory::systemManaged())],
            'amount' => ['required', 'numeric', 'min:1'],
            'description' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'bank_account_id.required' => 'Rekening bank wajib dipilih.',
            'bank_account_id.exists' => 'Rekening bank tidak valid atau tidak aktif.',
            'type.required' => 'Jenis transaksi wajib dipilih.',
            'kategori.required' => 'Kategori transaksi wajib dipilih.',
            'kategori.enum' => 'Kategori ini dicatat lewat menunya sendiri (Termin, Pinjaman Tukang, Hutang Supplier, Pindah Dana, Upah Tukang/Penggajian, atau Penalti), bukan transaksi manual.',
            'amount.required' => 'Nominal wajib diisi.',
            'description.required' => 'Deskripsi wajib diisi.',
        ];
    }
}
