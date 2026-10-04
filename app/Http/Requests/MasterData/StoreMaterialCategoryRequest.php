<?php

namespace App\Http\Requests\MasterData;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaterialCategoryRequest extends FormRequest
{
    /** Route-level `role:SUPERADMIN` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    /** The prefix is stored upper-case (KYP) — it starts every item code of the category. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code_prefix'))) {
            $this->merge(['code_prefix' => mb_strtoupper(trim($this->input('code_prefix')))]);
        }
    }

    public function rules(): array
    {
        $category = $this->route('material_category');

        return [
            'name' => ['required', 'string', 'max:50', Rule::unique('material_categories', 'name')->ignore($category)],
            'code_prefix' => ['required', 'string', 'regex:/^[A-Z]{2,5}$/', Rule::unique('material_categories', 'code_prefix')->ignore($category)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama kategori wajib diisi.',
            'name.unique' => 'Kategori ini sudah ada.',
            'code_prefix.required' => 'Prefix kode wajib diisi.',
            'code_prefix.regex' => 'Prefix kode 2–5 huruf, mis. KYP.',
            'code_prefix.unique' => 'Prefix kode ini sudah dipakai kategori lain.',
        ];
    }
}
