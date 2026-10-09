<?php

namespace App\Http\Requests\CompanyProfile;

/** Sprint 20 Sub 04 — same fields as Store, plus the position in the list. */
class UpdatePortfolioItemRequest extends StorePortfolioItemRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
