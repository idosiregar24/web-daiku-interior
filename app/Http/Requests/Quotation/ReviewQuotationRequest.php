<?php

namespace App\Http\Requests\Quotation;

use App\Models\QuotationItemReview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sprint 12 decision #8 — the PM / Asisten PM / CEO item review. Route-level
 * `role:PM|ASISTEN_PM|CEO` lets reviewers in; QuotationService::reviewStage()
 * decides whose turn it is (PM before CEO). "Every item marked" and "a ✘
 * needs a note" are checked there too, against the quotation's items.
 */
class ReviewQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approve', 'return'])],
            'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['array'],
            'items.*.item_id' => ['required', 'integer', 'distinct'],
            'items.*.verdict' => ['required', Rule::in([QuotationItemReview::VERDICT_OK, QuotationItemReview::VERDICT_SALAH])],
            'items.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'decision.required' => 'Pilih keputusan review.',
            'decision.in' => 'Keputusan review tidak valid.',
            'items.*.verdict.required' => 'Tandai item ✔ atau ✘.',
            'items.*.note.max' => 'Catatan item maksimal 500 karakter.',
        ];
    }
}
