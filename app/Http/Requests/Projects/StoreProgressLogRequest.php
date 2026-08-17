<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

class StoreProgressLogRequest extends FormRequest
{
    /** Route-level `role:PM` middleware already gates this action (PRD §7.1 "Progress Log" — PM has CRUD). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'percentage' => ['required', 'integer', 'min:0', 'max:100'],
            'description' => ['required', 'string'],
            'ref_urls' => ['nullable', 'array'],
            'ref_urls.*' => ['url'],
            'log_date' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'percentage.required' => 'Persentase progress wajib diisi.',
            'percentage.max' => 'Persentase tidak boleh lebih dari 100.',
            'description.required' => 'Deskripsi progress wajib diisi.',
            'ref_urls.*.url' => 'URL referensi tidak valid.',
        ];
    }
}
