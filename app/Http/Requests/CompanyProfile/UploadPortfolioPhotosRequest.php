<?php

namespace App\Http\Requests\CompanyProfile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 20 Sub 04 — raster photos only (no SVG: it can carry script and
 * the files are public). Resized to WebP by PortfolioPhotoService.
 */
class UploadPortfolioPhotosRequest extends FormRequest
{
    public const MAX_FILES = 12;

    /** Route-level `role:CEO|MARKETING` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'photos' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES],
            'photos.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:15360', 'dimensions:min_width=600,min_height=400,max_width=8000,max_height=8000'],
        ];
    }

    public function messages(): array
    {
        return [
            'photos.required' => 'Pilih minimal satu foto.',
            'photos.max' => 'Maksimal '.self::MAX_FILES.' foto sekali unggah.',
            'photos.*.mimes' => 'Foto harus berformat JPG, PNG, atau WEBP.',
            'photos.*.max' => 'Ukuran tiap foto maksimal 15 MB.',
            'photos.*.dimensions' => 'Foto minimal 600×400 piksel, maksimal 8000 piksel.',
        ];
    }
}
