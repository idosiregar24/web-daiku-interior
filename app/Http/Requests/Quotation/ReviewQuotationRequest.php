<?php

namespace App\Http\Requests\Quotation;

use App\Models\QuotationItemReview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sprint 12 decision #8 — the PM / Asisten PM item review (Sprint 23 K8:
 * the CEO no longer reviews). Route-level `role:PM|ASISTEN_PM`;
 * QuotationService::review() checks the status, that every item not
 * carried from an earlier ✔ (K7) is marked, and that a ✘ has a note.
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
