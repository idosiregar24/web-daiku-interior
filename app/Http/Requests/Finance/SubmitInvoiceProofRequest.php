<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Tandai Klien Sudah Bayar" (Sprint 19 Sub 01) — the proof link is
 * optional: a client may only phone in the payment, Finance then matches
 * it against the bank statement before verifying.
 */
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
            'payment_proof_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'payment_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_proof_url.url' => 'Link bukti bayar harus berupa URL http/https.',
            'payment_proof_url.max' => 'Link bukti bayar maksimal 500 karakter.',
            'payment_note.max' => 'Catatan pembayaran maksimal 500 karakter.',
        ];
    }
}
