<?php

namespace App\Http\Requests\Quotation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 15 K4 — the RAB's "Catatan" printed on the letter. Route-level
 * `role:ESTIMATOR`; QuotationService::saveClientNotes() allows DRAFT only.
 */
class SaveClientNotesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'client_notes.max' => 'Catatan maksimal 2000 karakter.',
        ];
    }
}
