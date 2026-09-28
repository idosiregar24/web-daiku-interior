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
    ];

    public const DISK = 'local';

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
        'logo_path',
        'favicon_path',
        'login_image_path',
    ];

    /** Storage paths stay server-side; the UI only ever gets the served URLs. */
    protected $hidden = ['logo_path', 'favicon_path', 'login_image_path'];

    protected $appends = ['logo_url', 'favicon_url', 'login_image_url'];

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
        $disk = Storage::disk(self::DISK);

        if (! $this->logo_path || ! $disk->exists($this->logo_path)) {
            return null;
        }

        return 'data:'.$disk->mimeType($this->logo_path).';base64,'.base64_encode($disk->get($this->logo_path));
    }
}
