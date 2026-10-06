<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SiteSetting extends Model
{
    /** Uploadable brand assets (route key → path column on the private `local` disk). */
    public const ASSETS = [
        'logo' => 'logo_path',
        'favicon' => 'favicon_path',
        'login_image' => 'login_image_path',
        // Sprint 15 — signs the letter-style PDFs; never served publicly (see PUBLIC_ASSETS).
        'signature' => 'signature_path',
    ];

    /** Assets the public `branding.show` route may serve (login page, browser tab, sidebar). */
    public const PUBLIC_ASSETS = ['logo', 'favicon', 'login_image'];

    public const DISK = 'local';

    /** Sprint 15 — the black footer line of every letter, after the company name. */
    public const DEFAULT_LETTER_FOOTER = 'INTERIOR FURNISHING | ARCHITECTURAL DESIGN | BUILDING CONSTRUCTION';

    /**
     * Sprint 15 K4 — "Catatan" printed on a RAB when its Estimator wrote none
     * (Pengaturan Situs overrides these). Keyed by QuotationType value.
     */
    public const DEFAULT_NOTES = [
        'SURVEY' => 'Biaya survey meliputi transportasi dan pengukuran lokasi oleh tim Daiku. Survey dijadwalkan setelah pembayaran diterima.',
        'DESAIN' => 'Paket desain meliputi: Desain Interior, Desain layout, 3D Render, Gambar spesifikasi, 2 kali Revisi Desain, dan Handover kit.',
        'PROYEK' => 'Harga sudah termasuk material, upah kerja, dan pemasangan sesuai rincian di atas. Pekerjaan di luar rincian dihitung sebagai pekerjaan tambah.',
    ];

    public const DEFAULT_NAME = 'Daiku Interior';

    public const DEFAULT_TAGLINE = 'Enterprise System';

    public const DEFAULT_LOGIN_HEADLINE = 'Satu sistem untuk seluruh alur proyek interior.';

    protected $fillable = [
        'site_name',
        'site_tagline',
        'login_headline',
        'company_address',
        'company_phone',
        'company_email',
        'company_instagram',
        'company_legal_name',
        'letter_footer',
        'signer_name',
        'signer_title',
        'note_survey',
        'note_desain',
        'note_proyek',
        'logo_path',
        'favicon_path',
        'login_image_path',
        'signature_path',
    ];

    /** Storage paths stay server-side; the UI only ever gets the served URLs. */
    protected $hidden = ['logo_path', 'favicon_path', 'login_image_path', 'signature_path'];

    protected $appends = ['logo_url', 'favicon_url', 'login_image_url', 'signature_url'];

    /**
     * Site settings are a singleton — there is always exactly one row.
     * Get it, creating it with defaults on first use (e.g. right after a
     * fresh migrate before any seeder has run).
     */
    public static function current(): self
    {
        // Explicit name: a just-created model doesn't carry the column's DB default.
        return static::query()->firstOrCreate([], ['site_name' => self::DEFAULT_NAME]);
    }

    /**
     * Public URL of an uploaded asset (BrandingAssetController), or null.
     * The `v` query changes with every upload (new random filename), so
     * browsers can cache the file forever and still see replacements.
     */
    public function assetUrl(string $asset): ?string
    {
        $path = $this->{self::ASSETS[$asset]};

        return $path ? route('branding.show', ['asset' => $asset, 'v' => substr(md5($path), 0, 10)]) : null;
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->assetUrl('logo'));
    }

    protected function faviconUrl(): Attribute
    {
        return Attribute::get(fn () => $this->assetUrl('favicon'));
    }

    protected function loginImageUrl(): Attribute
    {
        return Attribute::get(fn () => $this->assetUrl('login_image'));
    }

    /**
     * What every layout needs (sidebar brand, login page, browser tab) —
     * shared on each Inertia response by HandleInertiaRequests, guests
     * included. Empty optional text falls back to the defaults.
     *
     * @return array<string, string|null>
     */
    public function branding(): array
    {
        return [
            'name' => $this->site_name,
            'tagline' => $this->site_tagline ?: self::DEFAULT_TAGLINE,
            'loginHeadline' => $this->login_headline ?: self::DEFAULT_LOGIN_HEADLINE,
            'logoUrl' => $this->logo_url,
            'faviconUrl' => $this->favicon_url,
            'loginImageUrl' => $this->login_image_url,
        ];
    }

    /** The logo inlined as a data URI — DomPDF can't fetch our own routes. */
    public function logoDataUri(): ?string
    {
        return $this->assetDataUri('logo');
    }

    /** Sprint 15 — the signature, inlined (PDF, client link) instead of a public URL. */
    public function signatureDataUri(): ?string
    {
        return $this->assetDataUri('signature');
    }

    public function assetDataUri(string $asset): ?string
    {
        $path = $this->{self::ASSETS[$asset]};
        $disk = Storage::disk(self::DISK);

        if (! $path || ! $disk->exists($path)) {
            return null;
        }

        return 'data:'.$disk->mimeType($path).';base64,'.base64_encode($disk->get($path));
    }

    /** Sprint 15 — the settings page previews the signature without a public route. */
    protected function signatureUrl(): Attribute
    {
        return Attribute::get(fn () => $this->signatureDataUri());
    }

    /** Footer line, e.g. "DAIKU | INTERIOR FURNISHING | … | 2026". */
    public function letterFooterLine(?int $year = null): string
    {
        return strtoupper(implode(' | ', array_filter([
            preg_replace('/\s+interior$/i', '', $this->site_name ?: self::DEFAULT_NAME),
            $this->letter_footer ?: self::DEFAULT_LETTER_FOOTER,
            (string) ($year ?? now('Asia/Jakarta')->year),
        ])));
    }

    /** Sprint 15 K4 — the default "Catatan" for a RAB of this type. */
    public function defaultNoteFor(string $type): string
    {
        $column = 'note_'.strtolower($type);

        return trim((string) ($this->{$column} ?? '')) ?: (self::DEFAULT_NOTES[$type] ?? '');
    }
}
