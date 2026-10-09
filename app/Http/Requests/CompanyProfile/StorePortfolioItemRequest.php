<?php

namespace App\Http\Requests\CompanyProfile;

use App\Enums\ProjectType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sprint 20 Sub 04 — a portfolio item's text. Mirrored by the Zod schema
 * in Pages/Settings/Portfolio. The place is a district ("Panam"), never
 * the client's address.
 */
class StorePortfolioItemRequest extends FormRequest
{
    /** Route-level `role:CEO|MARKETING` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'project_type' => ['required', Rule::enum(ProjectType::class)],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'location_label' => ['nullable', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'min:1990', 'max:'.(now()->year + 1)],
            'summary' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:5000'],
            'client_consent' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Judul portofolio wajib diisi.',
            'title.max' => 'Judul maksimal 150 karakter.',
            'project_type.required' => 'Jenis proyek wajib dipilih.',
            'project_type.enum' => 'Jenis proyek tidak dikenal.',
            'city_id.exists' => 'Kota tidak ditemukan di Master Kota.',
            'location_label.max' => 'Lokasi maksimal 100 karakter.',
            'year.integer' => 'Tahun harus berupa angka.',
            'year.min' => 'Tahun minimal 1990.',
            'year.max' => 'Tahun tidak boleh melewati tahun depan.',
            'summary.max' => 'Ringkasan maksimal 300 karakter.',
            'description.max' => 'Cerita proyek maksimal 5000 karakter.',
        ];
    }
}
