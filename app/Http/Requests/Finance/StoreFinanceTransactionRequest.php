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
            // PRD §4.7 "Setiap transaksi wajib mencantumkan rekening bank".
            'bank_account_id' => ['required', 'exists:bank_accounts,id'],
            'type' => ['required', Rule::enum(FinanceTransactionType::class)],
            'kategori' => ['required', Rule::enum(FinanceCategory::class)],
            'amount' => ['required', 'numeric', 'min:1'],
            'description' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'bank_account_id.required' => 'Rekening bank wajib dipilih.',
            'type.required' => 'Jenis transaksi wajib dipilih.',
            'kategori.required' => 'Kategori transaksi wajib dipilih.',
            'amount.required' => 'Nominal wajib diisi.',
            'description.required' => 'Deskripsi wajib diisi.',
        ];
    }
}
