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
                // Sprint 15 — a PNG (ideally transparent) signature for the letters.
                'signature' => ['required', 'file', 'mimes:png', 'max:1024', 'dimensions:min_width=120,max_width=2000,max_height=1000'],
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
                'signature' => 'Tanda tangan harus berformat PNG (disarankan latar transparan).',
                default => 'Gambar harus berformat JPG, PNG, atau WEBP.',
            },
            'file.max' => match ($this->route('asset')) {
                'logo' => 'Ukuran logo maksimal 2 MB.',
                'favicon' => 'Ukuran favicon maksimal 512 KB.',
                'signature' => 'Ukuran tanda tangan maksimal 1 MB.',
                default => 'Ukuran gambar maksimal 5 MB.',
            },
            'file.dimensions' => match ($this->route('asset')) {
                'login_image' => 'Gambar halaman login minimal 600×600 piksel.',
                'signature' => 'Tanda tangan minimal lebar 120 piksel, maksimal 2000×1000 piksel.',
                default => 'Dimensi logo harus antara 32 dan 4000 piksel.',
            },
        ];
    }
}
