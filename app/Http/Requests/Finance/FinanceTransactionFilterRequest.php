<?php

namespace App\Http\Requests\Finance;

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query-string filters shared by the Transactions page and its Excel
 * export (FinanceTransaction::scopeFilter()), so the export always
 * matches what the page shows.
 */
class FinanceTransactionFilterRequest extends FormRequest
{
    /** Route-level `role:CEO|PM|FINANCE` middleware already gates both actions (PRD §7.1 "Finance – Transaction" — R). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::in(array_column(FinanceTransactionType::cases(), 'value'))],
            'project_id' => ['nullable', 'integer'],
            'bank_account_id' => ['nullable', 'integer'],
            'kategori' => ['nullable', Rule::in(array_column(FinanceCategory::cases(), 'value'))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.in' => 'Jenis transaksi tidak valid.',
            'project_id.integer' => 'Proyek tidak valid.',
            'bank_account_id.integer' => 'Rekening tidak valid.',
            'kategori.in' => 'Kategori tidak valid.',
            'from.date_format' => 'Tanggal awal tidak valid.',
            'to.date_format' => 'Tanggal akhir tidak valid.',
            'to.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
        ];
    }

    /**
     * @return array{type?: ?string, project_id?: ?int, bank_account_id?: ?int, kategori?: ?string, from?: ?string, to?: ?string}
     */
    public function filters(): array
    {
        return array_filter($this->validated(), fn ($value) => $value !== null && $value !== '');
    }
}
