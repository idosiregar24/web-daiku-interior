<?php

namespace App\Http\Requests\CompanyProfile;

use Illuminate\Foundation\Http\FormRequest;

/** Sprint 20 Sub 06 — a service page's top photo (resized to WebP). */
class UploadServicePageHeroRequest extends FormRequest
{
    /** Route-level `role:CEO|MARKETING` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:15360', 'dimensions:min_width=800,min_height=500,max_width=8000,max_height=8000'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Pilih foto yang akan diunggah.',
            'file.mimes' => 'Foto harus berformat JPG, PNG, atau WEBP.',
            'file.max' => 'Ukuran foto maksimal 15 MB.',
            'file.dimensions' => 'Foto minimal 800×500 piksel, maksimal 8000 piksel.',
        ];
    }
}
