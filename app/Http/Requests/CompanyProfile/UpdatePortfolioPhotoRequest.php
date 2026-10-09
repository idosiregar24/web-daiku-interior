<?php

namespace App\Http\Requests\CompanyProfile;

use Illuminate\Foundation\Http\FormRequest;

/** Sprint 20 Sub 04 — a photo's alt text (accessibility, Google Images) and caption. */
class UpdatePortfolioPhotoRequest extends FormRequest
{
    /** Route-level `role:CEO|MARKETING` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'alt' => ['nullable', 'string', 'max:200'],
            'caption' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'alt.max' => 'Deskripsi foto maksimal 200 karakter.',
            'caption.max' => 'Keterangan maksimal 200 karakter.',
        ];
    }
}
