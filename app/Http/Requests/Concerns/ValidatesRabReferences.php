<?php

namespace App\Http\Requests\Concerns;

use App\Models\QuotationReference;

/**
 * Sprint 14 Sub 01 — the reference links & photos a RAB request may carry
 * (lead "Minta RAB …" and project "Minta RAB Tambahan"). Links must be
 * http/https (no `javascript:`), photos real images within the limits.
 */
trait ValidatesRabReferences
{
    /** @return array<string, array<int, string>> */
    protected function referenceRules(): array
    {
        return [
            'reference_links' => ['nullable', 'array', 'max:'.QuotationReference::MAX_LINKS],
            'reference_links.*' => ['required', 'string', 'max:500', 'url:http,https'],
            'reference_photos' => ['nullable', 'array', 'max:'.QuotationReference::MAX_PHOTOS],
            'reference_photos.*' => ['required', 'file', 'image', 'mimes:'.implode(',', QuotationReference::PHOTO_MIMES), 'max:'.QuotationReference::MAX_PHOTO_KB],
        ];
    }

    /** @return array<string, string> */
    protected function referenceMessages(): array
    {
        return [
            'reference_links.max' => 'Maksimal '.QuotationReference::MAX_LINKS.' link referensi.',
            'reference_links.*.url' => 'Link referensi harus berupa alamat http/https.',
            'reference_links.*.max' => 'Link referensi terlalu panjang (maks. 500 karakter).',
            'reference_photos.max' => 'Maksimal '.QuotationReference::MAX_PHOTOS.' foto referensi.',
            'reference_photos.*.image' => 'Foto referensi harus berupa gambar.',
            'reference_photos.*.mimes' => 'Foto referensi harus JPG, PNG, atau WEBP.',
            'reference_photos.*.max' => 'Ukuran tiap foto maksimal '.(QuotationReference::MAX_PHOTO_KB / 1024).' MB.',
            'reference_photos.*.uploaded' => 'Foto gagal diunggah — mungkin terlalu besar.',
        ];
    }
}
