<?php

namespace App\Http\Requests\Quotation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 12 decision #13 — the client's "Setujui Penawaran" on the public
 * link. Anyone holding the link may submit (the token is the credential);
 * the tick "Saya telah membaca dan menyetujui penawaran ini" is mandatory.
 */
class ApprovePublicQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'agree' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'agree.accepted' => 'Centang pernyataan persetujuan terlebih dahulu.',
        ];
    }
}
