<?php

namespace App\Http\Requests\Quotation;

use App\Models\QuotationNote;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 23 K11 — the client's "Tidak Setuju" on the public link. Anyone
 * holding the link may submit (the token is the credential, throttled
 * route); a reason is mandatory and capped so the public form can't be
 * used to store arbitrary bulk text.
 */
class RejectPublicQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'min:3', 'max:'.QuotationNote::MAX_LENGTH],
        ];
    }

    public function messages(): array
    {
        return [
            'note.required' => 'Tuliskan alasan Anda tidak setuju agar kami bisa memperbaiki penawarannya.',
            'note.min' => 'Alasan minimal 3 karakter.',
            'note.max' => 'Alasan maksimal '.QuotationNote::MAX_LENGTH.' karakter.',
        ];
    }
}
