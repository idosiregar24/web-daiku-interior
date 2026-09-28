<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One brand asset upload (`{asset}` route parameter, constrained to
 * SiteSetting::ASSETS). Raster formats only — SVG is deliberately not
 * accepted: it can carry script and the assets are served publicly.
 */
class UploadBrandingAssetRequest extends FormRequest
{
    /** Route-level `role:CEO|SUPERADMIN` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => match ($this->route('asset')) {
                'logo' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:2048', 'dimensions:min_width=32,min_height=32,max_width=4000,max_height=4000'],
                'favicon' => ['required', 'file', 'mimes:png,ico', 'max:512'],
                'login_image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=600,min_height=600'],
            },
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Pilih file yang akan diunggah.',
            'file.mimes' => match ($this->route('asset')) {
                'logo' => 'Logo harus berformat PNG atau JPG.',
                'favicon' => 'Favicon harus berformat PNG atau ICO.',
                default => 'Gambar harus berformat JPG, PNG, atau WEBP.',
            },
            'file.max' => match ($this->route('asset')) {
                'logo' => 'Ukuran logo maksimal 2 MB.',
                'favicon' => 'Ukuran favicon maksimal 512 KB.',
                default => 'Ukuran gambar maksimal 5 MB.',
            },
            'file.dimensions' => $this->route('asset') === 'login_image'
                ? 'Gambar halaman login minimal 600×600 piksel.'
                : 'Dimensi logo harus antara 32 dan 4000 piksel.',
        ];
    }
}
