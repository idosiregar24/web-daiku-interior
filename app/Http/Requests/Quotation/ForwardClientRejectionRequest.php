<?php

namespace App\Http\Requests\Quotation;

use App\Models\QuotationNote;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 23 K11 — "Teruskan ke Estimator" on a RAB the client declined on
 * the link: Marketing writes what is missing. Route-level
 * `role:CEO|MARKETING` (the people who talk to the client);
 * QuotationService::forwardClientRejection() checks the status.
 */
class ForwardClientRejectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:'.QuotationNote::MAX_LENGTH],
        ];
    }

    public function messages(): array
    {
        return [
            'note.required' => 'Catatan untuk Estimator wajib diisi.',
            'note.max' => 'Catatan maksimal '.QuotationNote::MAX_LENGTH.' karakter.',
        ];
    }
}
