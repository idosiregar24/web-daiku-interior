<?php

namespace App\Http\Requests\Finance;

use App\Enums\FinanceCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinanceAllocationConfigRequest extends FormRequest
{
    /** Route-level `role:CEO|FINANCE` middleware already gates this action (PRD §4.7). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:50', Rule::unique('finance_allocation_configs', 'label')],
            'percentage' => ['required', 'numeric', 'gt:0', 'max:100'],
            'kategori' => ['required', Rule::enum(FinanceCategory::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'label.required' => 'Label alokasi wajib diisi.',
            'label.unique' => 'Label alokasi ini sudah dipakai.',
            'percentage.required' => 'Persentase wajib diisi.',
            'percentage.gt' => 'Persentase harus lebih dari 0.',
            'percentage.max' => 'Persentase maksimal 100.',
            'kategori.required' => 'Kategori wajib dipilih.',
            'kategori.enum' => 'Kategori tidak valid.',
        ];
    }
}
