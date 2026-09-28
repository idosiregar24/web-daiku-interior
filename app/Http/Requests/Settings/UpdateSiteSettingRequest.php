<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSiteSettingRequest extends FormRequest
{
    /** Route-level `role:CEO|SUPERADMIN` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'site_name' => ['required', 'string', 'max:100'],
            'site_tagline' => ['nullable', 'string', 'max:60'],
            'login_headline' => ['nullable', 'string', 'max:120'],
            'company_address' => ['nullable', 'string'],
            'company_phone' => ['nullable', 'string', 'max:50'],
            'company_email' => ['nullable', 'email', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'site_name.required' => 'Nama sistem wajib diisi.',
            'site_name.max' => 'Nama sistem maksimal 100 karakter.',
            'site_tagline.max' => 'Tagline maksimal 60 karakter.',
            'login_headline.max' => 'Judul halaman login maksimal 120 karakter.',
            'company_email.email' => 'Format email tidak valid.',
        ];
    }
}
