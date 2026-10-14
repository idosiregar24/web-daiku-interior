<?php

namespace App\Http\Requests\Finance;

use App\Models\InvoicePaymentProof;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Tandai Klien Sudah Bayar" (Sprint 19 Sub 01) — the proof link is
 * optional: a client may only phone in the payment, Finance then matches
 * it against the bank statement before verifying. Sprint 23 K12d: up to 3
 * proof photos, optional too — the type is checked from the file's
 * content (never its name), and InvoicePaymentProofService re-encodes it.
 * Mirrored by `proofSchema` in Components/modules/finance/InvoiceDialogs.tsx.
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
        $mimes = implode(',', InvoicePaymentProof::PHOTO_MIMES);

        return [
            // A link (Drive, WhatsApp media, bank e-receipt) — http/https only, never javascript:.
            'payment_proof_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'payment_note' => ['nullable', 'string', 'max:500'],
            'payment_photos' => ['nullable', 'array', 'max:'.InvoicePaymentProof::MAX_PHOTOS],
            'payment_photos.*' => [
                'file',
                "mimes:{$mimes}",
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:'.InvoicePaymentProof::MAX_PHOTO_KB,
                // Decoding a huge canvas in GD would exhaust memory long before 5 MB says so.
                'dimensions:max_width=12000,max_height=12000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_proof_url.url' => 'Link bukti bayar harus berupa URL http/https.',
            'payment_proof_url.max' => 'Link bukti bayar maksimal 500 karakter.',
            'payment_note.max' => 'Catatan pembayaran maksimal 500 karakter.',
            'payment_photos.array' => 'Foto bukti bayar tidak valid.',
            'payment_photos.max' => 'Maksimal '.InvoicePaymentProof::MAX_PHOTOS.' foto bukti bayar.',
            'payment_photos.*.file' => 'Foto bukti bayar gagal diunggah.',
            'payment_photos.*.mimes' => 'Foto bukti bayar harus JPG, PNG, atau WEBP.',
            'payment_photos.*.mimetypes' => 'Foto bukti bayar harus JPG, PNG, atau WEBP.',
            'payment_photos.*.max' => 'Ukuran foto bukti bayar maksimal 5 MB.',
            'payment_photos.*.dimensions' => 'Resolusi foto bukti bayar terlalu besar.',
        ];
    }
}
