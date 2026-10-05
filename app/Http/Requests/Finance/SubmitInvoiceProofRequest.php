<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class SubmitInvoiceProofRequest extends FormRequest
{
    /** Route-level `role:MARKETING|FINANCE` gates this. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A link (Drive, WhatsApp media, bank e-receipt) — http/https only, never javascript:.
            'payment_proof_url' => ['required', 'string', 'max:500', 'url:http,https'],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_proof_url.required' => 'Link bukti bayar wajib diisi.',
            'payment_proof_url.url' => 'Link bukti bayar harus berupa URL http/https.',
        ];
    }
}
