<?php

namespace App\Http\Requests\Quotation;

use App\Models\QuotationNote;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 23 K9 — the CEO's information-only note on a RAB. Route-level
 * `role:CEO`; QuotationService::addCeoNote() refuses a cancelled RAB.
 */
class StoreQuotationNoteRequest extends FormRequest
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
            'note.required' => 'Catatan wajib diisi.',
            'note.max' => 'Catatan maksimal '.QuotationNote::MAX_LENGTH.' karakter.',
        ];
    }
}
