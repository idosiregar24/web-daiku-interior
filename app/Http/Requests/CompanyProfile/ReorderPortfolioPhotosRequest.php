<?php

namespace App\Http\Requests\CompanyProfile;

use Illuminate\Foundation\Http\FormRequest;

/** Sprint 20 Sub 04 — the item's photo ids in their new order. */
class ReorderPortfolioPhotosRequest extends FormRequest
{
    /** Route-level `role:CEO|MARKETING` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ];
    }
}
