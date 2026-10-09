<?php

namespace App\Http\Requests\Settings;

use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sprint 20 Sub 05 — Pengaturan Situs → "Profil Publik" (the company
 * profile at `/`). Mirrored by the Zod schema in Settings/Edit.tsx.
 */
class UpdatePublicProfileRequest extends FormRequest
{
    /** Only Google's own embed URL may be framed on the site. */
    public const MAPS_EMBED_PREFIX = 'https://www.google.com/maps/embed?';

    /** Route-level `role:CEO|SUPERADMIN` middleware already gates this action. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $maps = trim((string) $this->input('maps_embed_url'));

        // Google's "Sematkan peta" gives a whole <iframe>; keep only its src.
        if (preg_match('/<iframe[^>]+src="([^"]+)"/i', $maps, $match)) {
            $maps = html_entity_decode($match[1]);
        }

        $verification = trim((string) $this->input('google_site_verification'));

        // Search Console hands out a whole <meta> tag; keep only its content.
        if (preg_match('/content="([^"]+)"/i', $verification, $match)) {
            $verification = $match[1];
        }

        $this->merge([
            'whatsapp_phone' => Phone::normalize($this->input('whatsapp_phone')),
            'maps_embed_url' => $maps === '' ? null : $maps,
            'google_site_verification' => $verification === '' ? null : $verification,
        ]);
    }

    public function rules(): array
    {
        return [
            'public_tagline' => ['nullable', 'string', 'max:120'],
            'hero_headline' => ['nullable', 'string', 'max:120'],
            'hero_subheadline' => ['nullable', 'string', 'max:300'],
            'about_text' => ['nullable', 'string', 'max:3000'],
            'founded_year' => ['nullable', 'integer', 'min:1950', 'max:'.now()->year],
            'stat_projects' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'stat_cities' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'service_area_text' => ['nullable', 'string', 'max:300'],
            'whatsapp_phone' => ['nullable', 'string', 'regex:'.Phone::PATTERN],
            'whatsapp_greeting' => ['nullable', 'string', 'max:200'],
            'maps_embed_url' => ['nullable', 'string', 'max:1000', 'url', 'starts_with:'.self::MAPS_EMBED_PREFIX],
            'opening_hours' => ['nullable', 'string', 'max:200'],
            'google_site_verification' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'public_tagline.max' => 'Tagline maksimal 120 karakter.',
            'hero_headline.max' => 'Judul utama maksimal 120 karakter.',
            'hero_subheadline.max' => 'Subjudul maksimal 300 karakter.',
            'about_text.max' => 'Teks Tentang Kami maksimal 3000 karakter.',
            'founded_year.integer' => 'Tahun berdiri harus berupa angka.',
            'founded_year.min' => 'Tahun berdiri minimal 1950.',
            'founded_year.max' => 'Tahun berdiri tidak boleh melewati tahun ini.',
            'stat_projects.integer' => 'Jumlah proyek harus berupa angka.',
            'stat_projects.min' => 'Jumlah proyek minimal 1.',
            'stat_cities.integer' => 'Jumlah kota harus berupa angka.',
            'stat_cities.min' => 'Jumlah kota minimal 1.',
            'service_area_text.max' => 'Area layanan maksimal 300 karakter.',
            'whatsapp_phone.regex' => 'Nomor WhatsApp harus diawali 08 dan berisi 10–13 angka.',
            'whatsapp_greeting.max' => 'Pesan pembuka maksimal 200 karakter.',
            'maps_embed_url.url' => 'Link peta harus berupa URL embed Google Maps.',
            'maps_embed_url.starts_with' => 'Link peta harus dari Google Maps → Bagikan → Sematkan peta (diawali https://www.google.com/maps/embed?).',
            'maps_embed_url.max' => 'Link peta maksimal 1000 karakter.',
            'opening_hours.max' => 'Jam buka maksimal 200 karakter.',
            'google_site_verification.regex' => 'Kode verifikasi hanya berisi huruf, angka, - dan _.',
            'google_site_verification.max' => 'Kode verifikasi maksimal 100 karakter.',
        ];
    }
}
