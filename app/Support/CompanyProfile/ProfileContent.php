<?php

namespace App\Support\CompanyProfile;

use App\Enums\ProjectType;
use App\Models\City;
use App\Models\PortfolioItem;
use App\Models\PortfolioPhoto;
use App\Models\ServicePage;
use App\Models\SiteSetting;
use App\Models\Testimonial;
use App\Support\Phone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sprint 20 — everything the public company profile shows, in one place.
 * Reads Pengaturan Situs (Profil Publik), the published portfolio,
 * testimonials and service pages, and falls back to Placeholder only for
 * what is still empty (and only while placeholders are on). The Blade
 * views read this — never a model — so a section never has to know where
 * its text comes from. One instance per request (AppServiceProvider),
 * shared with every `site.*` view as `$profile`.
 */
final class ProfileContent
{
    /** The real flow of a Daiku project (Sprint 12), not marketing copy. */
    public const PROCESS = [
        ['title' => 'Konsultasi', 'text' => 'Ceritakan kebutuhan, ukuran ruang dan anggaran lewat WhatsApp. Gratis, tanpa kewajiban.'],
        ['title' => 'Survei Lokasi', 'text' => 'Tim kami datang mengukur ruang dan melihat kondisi lapangan sebelum menggambar.'],
        ['title' => 'Desain 3D', 'text' => 'Arsitek menyusun desain dan gambar kerja. Anda bisa minta revisi sebelum produksi.'],
        ['title' => 'RAB Transparan', 'text' => 'Penawaran rinci per item: material, ukuran dan harga. Anda menyetujuinya lewat link penawaran.'],
        ['title' => 'Produksi & Pemasangan', 'text' => 'Dikerjakan per tahap, mengikuti jadwal dan termin pembayaran yang disepakati.'],
        ['title' => 'QA per Tahap', 'text' => 'Setiap tahap diperiksa tim QA sebelum pekerjaan berikutnya dimulai.'],
        ['title' => 'Serah Terima', 'text' => 'Hasil akhir dicek bersama Anda sebelum proyek ditutup.'],
    ];

    private const DAYS = [
        'senin' => 'Mo', 'selasa' => 'Tu', 'rabu' => 'We', 'kamis' => 'Th',
        'jumat' => 'Fr', "jum'at" => 'Fr', 'sabtu' => 'Sa', 'minggu' => 'Su',
    ];

    /** @var Collection<string, ServicePage>|null keyed by project type */
    private ?Collection $servicePages = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $services = null;

    public function __construct(private readonly SiteSetting $site) {}

    public function usesPlaceholders(): bool
    {
        return (bool) config('daiku.company_profile.placeholders');
    }

    // ── Identity ────────────────────────────────────────────────────────

    public function name(): string
    {
        return $this->site->site_name ?: SiteSetting::DEFAULT_NAME;
    }

    public function legalName(): ?string
    {
        return $this->filled($this->site->company_legal_name);
    }

    public function tagline(): ?string
    {
        return $this->text($this->site->public_tagline, Placeholder::PUBLIC_TAGLINE);
    }

    public function city(): string
    {
        return ServiceCatalog::city();
    }

    public function logoUrl(): ?string
    {
        return $this->site->logo_url;
    }

    public function faviconUrl(): ?string
    {
        return $this->site->favicon_url ?? $this->site->logo_url;
    }

    /** Three pillars from the letter footer ("Interior Furnishing", …). */
    public function pillars(): array
    {
        $footer = $this->site->letter_footer ?: SiteSetting::DEFAULT_LETTER_FOOTER;

        return array_values(array_filter(array_map(
            fn (string $part) => Str::title(mb_strtolower(trim($part))),
            explode('|', $footer),
        )));
    }

    // ── Hero, about, numbers ────────────────────────────────────────────

    /** Always set: an empty headline falls back to the plain "what & where" line. */
    public function heroHeadline(): string
    {
        return $this->filled($this->site->hero_headline) ?? Placeholder::heroHeadline($this->city());
    }

    public function heroSubheadline(): string
    {
        return $this->filled($this->site->hero_subheadline) ?? Placeholder::HERO_SUBHEADLINE;
    }

    /** True once a real hero photo is uploaded (until then the stand-in photo is used). */
    public function hasHeroPhoto(): bool
    {
        return (bool) $this->site->hero_image_path;
    }

    /** @return array{src: string, srcset: string|null, width: int, height: int, alt: string}|null */
    public function heroImage(): ?array
    {
        $path = $this->site->hero_image_path;
        $alt = 'Hasil pekerjaan interior '.$this->name().' di '.$this->city();

        if ($path && ($size = $this->storedImageSize(SiteSetting::DISK, $path))) {
            return ['src' => $this->site->hero_image_url, 'srcset' => null, 'width' => $size[0], 'height' => $size[1], 'alt' => $alt];
        }

        if (! $this->usesPlaceholders()) {
            return null;
        }

        [$file, $width, $height] = Placeholder::HERO_IMAGE;
        [$small, $smallWidth] = Placeholder::HERO_IMAGE_SMALL;

        return [
            'src' => asset($file),
            'srcset' => asset($small).' '.$smallWidth.'w, '.asset($file).' '.$width.'w',
            'width' => $width,
            'height' => $height,
            'alt' => 'Foto contoh ruang tamu modern',
        ];
    }

    /** @return list<string> paragraphs */
    public function about(): array
    {
        $text = $this->text($this->site->about_text, Placeholder::about($this->city()));

        return $text === null ? [] : array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', $text))));
    }

    /**
     * Uploaded hero, else the portrait drawing — the contact card.
     *
     * @return array{src: string, width: int, height: int, alt: string}|null
     */
    public function featureImage(): ?array
    {
        if ($this->hasHeroPhoto()) {
            return $this->heroImage();
        }

        if (! $this->usesPlaceholders()) {
            return null;
        }

        [$file, $width, $height] = Placeholder::FEATURE_IMAGE;

        return ['src' => asset($file), 'width' => $width, 'height' => $height, 'alt' => 'Foto contoh ruang keluarga'];
    }

    /**
     * The photo card of the home page's bento row: the newest published
     * portfolio cover, else (placeholders on) the stand-in photo.
     *
     * @return array{src: string, srcset: string|null, width: int, height: int, alt: string}|null
     */
    public function bentoImage(): ?array
    {
        $item = PortfolioItem::query()->published()->with(['cover', 'photos'])->ordered()->first();

        if ($photo = $item?->coverPhoto()) {
            return $this->photo($photo, $item->title);
        }

        if (! $this->usesPlaceholders()) {
            return null;
        }

        [$file, $width, $height] = Placeholder::BENTO_IMAGE;

        return ['src' => asset($file), 'srcset' => null, 'width' => $width, 'height' => $height, 'alt' => 'Foto contoh ruang kerja'];
    }

    /**
     * "First sentence. The rest…" → [lead, rest] — the site sets the lead
     * dark and the rest muted (the two-tone statements of the design).
     *
     * @return array{0: string, 1: string}
     */
    public static function lead(string $text): array
    {
        $parts = preg_split('/(?<=[.!?])\s+/u', trim($text), 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    /** The big number of the home page: finished projects, else the number of services. */
    public function headlineStat(): array
    {
        $projects = $this->site->stat_projects ?: ($this->usesPlaceholders() ? Placeholder::STAT_PROJECTS : null);

        return $projects
            ? ['value' => number_format($projects, 0, ',', '.').'+', 'label' => 'Proyek selesai']
            : ['value' => (string) count(ServiceCatalog::all()), 'label' => 'Jenis layanan'];
    }

    /**
     * The row of numbers under the bento cards: founding year and cities
     * (Profil Publik), then what the site itself knows (services, steps).
     *
     * @return list<array{value: string, label: string, count: bool}> `count` = animate counting up (not a year)
     */
    public function facts(): array
    {
        $placeholders = $this->usesPlaceholders();
        $year = $this->site->founded_year ?: ($placeholders ? Placeholder::FOUNDED_YEAR : null);
        $cities = $this->site->stat_cities ?: ($placeholders ? Placeholder::STAT_CITIES : null);

        return array_values(array_filter([
            $year ? ['value' => (string) $year, 'label' => 'Tahun berdiri', 'count' => false] : null,
            $cities ? ['value' => (string) $cities, 'label' => 'Kota terlayani', 'count' => true] : null,
            ['value' => (string) count(ServiceCatalog::all()), 'label' => 'Jenis layanan', 'count' => true],
            ['value' => (string) count(self::PROCESS), 'label' => 'Tahap kerja per proyek', 'count' => true],
        ]));
    }

    /** @return list<array{value: string, label: string}> */
    public function stats(): array
    {
        $placeholders = $this->usesPlaceholders();
        $year = $this->site->founded_year ?: ($placeholders ? Placeholder::FOUNDED_YEAR : null);
        $projects = $this->site->stat_projects ?: ($placeholders ? Placeholder::STAT_PROJECTS : null);
        $cities = $this->site->stat_cities ?: ($placeholders ? Placeholder::STAT_CITIES : null);

        return array_values(array_filter([
            $year ? ['value' => (string) $year, 'label' => 'Tahun berdiri'] : null,
            $projects ? ['value' => number_format($projects, 0, ',', '.').'+', 'label' => 'Proyek selesai'] : null,
            $cities ? ['value' => (string) $cities, 'label' => 'Kota terlayani'] : null,
        ]));
    }

    public function serviceArea(): ?string
    {
        return $this->text($this->site->service_area_text, Placeholder::serviceArea($this->city()));
    }

    // ── Contact (K1: WhatsApp is the only way in) ───────────────────────

    public function address(): ?string
    {
        return $this->filled($this->site->company_address);
    }

    public function email(): ?string
    {
        return $this->filled($this->site->company_email);
    }

    /** "0811-7597-766" for display; null when no valid number is set. */
    public function phone(): ?string
    {
        $number = $this->whatsappNumber();

        return $number ? Phone::format($number) : null;
    }

    /** "+628117597766" (JSON-LD, tel:). */
    public function internationalPhone(): ?string
    {
        $number = $this->whatsappNumber();

        return $number ? '+'.Phone::whatsapp($number) : null;
    }

    public function instagramHandle(): ?string
    {
        $handle = ltrim(trim((string) $this->site->company_instagram), '@');

        return $handle === '' ? null : $handle;
    }

    public function instagramUrl(): ?string
    {
        $handle = $this->instagramHandle();

        return $handle ? 'https://www.instagram.com/'.rawurlencode($handle).'/' : null;
    }

    public function mapsEmbedUrl(): ?string
    {
        return $this->filled($this->site->maps_embed_url);
    }

    public function openingHours(): ?string
    {
        return $this->text($this->site->opening_hours, Placeholder::OPENING_HOURS);
    }

    public function googleSiteVerification(): ?string
    {
        return $this->filled($this->site->google_site_verification);
    }

    /**
     * `https://wa.me/62…?text=…` — every contact button of the site. The
     * message says the visitor came from the website (Marketing records
     * the lead with source "Website") and what they looked at.
     *
     * @param  string|null  $context  e.g. "Kitchen Set" or 'proyek "Cafe 60 m²"'
     */
    public function whatsappUrl(?string $context = null): string
    {
        $greeting = $this->filled($this->site->whatsapp_greeting)
            ?? strtr(Placeholder::WHATSAPP_GREETING, [':name' => $this->name()]);
        $message = rtrim($greeting, ' .').($context ? ' '.$context : '').'.';
        $number = Phone::whatsapp($this->whatsappNumber());

        // Without a number WhatsApp still opens and lets the visitor pick a chat.
        return 'https://wa.me/'.($number ?? '').'?text='.rawurlencode($message);
    }

    private function whatsappNumber(): ?string
    {
        foreach ([$this->site->whatsapp_phone, $this->site->company_phone] as $candidate) {
            $number = Phone::normalize($candidate);

            if (Phone::isValid($number)) {
                return $number;
            }
        }

        return null;
    }

    // ── Services, process ───────────────────────────────────────────────

    /**
     * Every service of ServiceCatalog with its one-line description and
     * the page it links to.
     *
     * @return list<array{entry: ServiceEntry, name: string, blurb: string, url: string, published: bool, image: array<string, mixed>|null}>
     */
    public function services(): array
    {
        return $this->services ??= array_map(function (ServiceEntry $entry) {
            $page = $this->servicePage($entry);

            return [
                'entry' => $entry,
                'name' => $entry->name,
                'blurb' => ($page?->is_published ? $page->meta_description : null)
                    ?: (Placeholder::SERVICE_BLURBS[$entry->type->value] ?? ''),
                'url' => $entry->url(),
                'published' => (bool) $page?->is_published,
                'image' => $this->serviceImage($entry),
            ];
        }, ServiceCatalog::all());
    }

    /**
     * A service's picture: its page's own photo, else the cover of its
     * newest published portfolio item, else (placeholders on) its drawing.
     *
     * @return array{src: string, srcset: string|null, width: int, height: int, alt: string, placeholder: bool}|null
     */
    public function serviceImage(ServiceEntry $entry): ?array
    {
        $page = $this->servicePage($entry);
        $alt = $entry->name.' oleh '.$this->name().' di '.$this->city();

        if ($page?->hero_image) {
            return ['src' => $page->heroUrl(), 'srcset' => null, 'width' => $page->hero_width, 'height' => $page->hero_height, 'alt' => $alt, 'placeholder' => false];
        }

        $item = PortfolioItem::query()->published()->ofTypes($entry->types)->with(['cover', 'photos'])->ordered()->first();

        if ($photo = $item?->coverPhoto()) {
            return [...$this->photo($photo, $alt), 'placeholder' => false];
        }

        if (! $this->usesPlaceholders() || ! isset(Placeholder::SERVICE_IMAGES[$entry->type->value])) {
            return null;
        }

        [$width, $height] = Placeholder::PORTFOLIO_IMAGE_SIZE;

        return ['src' => asset(Placeholder::SERVICE_IMAGES[$entry->type->value]), 'srcset' => null, 'width' => $width, 'height' => $height, 'alt' => 'Foto contoh '.mb_strtolower($entry->name), 'placeholder' => true];
    }

    public function servicePage(ServiceEntry $entry): ?ServicePage
    {
        $this->servicePages ??= ServicePage::query()->get()->keyBy(fn (ServicePage $page) => $page->project_type->value);

        return $this->servicePages->get($entry->type->value);
    }

    /** @return list<array{title: string, text: string}> */
    public function process(): array
    {
        return self::PROCESS;
    }

    // ── Portfolio & testimonials ────────────────────────────────────────

    /**
     * Published portfolio as cards; the placeholders while there is none.
     *
     * @param  list<ProjectType>|null  $types  only these project types (a service page)
     * @return list<array<string, mixed>>
     */
    public function portfolio(int $limit = 6, ?array $types = null): array
    {
        $items = PortfolioItem::query()
            ->published()
            ->when($types, fn ($query) => $query->ofTypes($types))
            ->with(['city', 'cover', 'photos'])
            ->ordered()
            ->limit($limit)
            ->get();

        if ($items->isNotEmpty()) {
            return $items->map(fn (PortfolioItem $item) => $this->portfolioCard($item))->all();
        }

        if (! $this->usesPlaceholders()) {
            return [];
        }

        $values = $types ? array_map(fn (ProjectType $type) => $type->value, $types) : null;
        [$width, $height] = Placeholder::PORTFOLIO_IMAGE_SIZE;

        return collect(Placeholder::PORTFOLIO)
            ->filter(fn (array $item) => $values === null || in_array($item['type'], $values, true))
            ->take($limit)
            ->map(fn (array $item) => [
                'title' => $item['title'],
                'url' => null,
                'type' => ProjectType::from($item['type'])->label(),
                'place' => $item['location'].', '.$this->city(),
                'year' => $item['year'],
                'image' => ['src' => asset($item['image']), 'srcset' => null, 'width' => $width, 'height' => $height, 'alt' => 'Foto contoh '.mb_strtolower($item['title'])],
                'placeholder' => true,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function portfolioCard(PortfolioItem $item): array
    {
        $photo = $item->coverPhoto();

        return [
            'title' => $item->title,
            'url' => route('site.portfolio.show', $item->slug),
            'type' => $item->project_type->label(),
            'place' => $item->placeLabel(),
            'year' => $item->year,
            'image' => $photo ? $this->photo($photo, $item->title) : null,
            'placeholder' => false,
        ];
    }

    /** @return array{src: string, srcset: string, width: int, height: int, alt: string} */
    public function photo(PortfolioPhoto $photo, string $fallbackAlt): array
    {
        return [
            'src' => $photo->thumb_url,
            'srcset' => $photo->thumb_url.' '.$photo->thumbWidth().'w, '.$photo->url.' '.$photo->width.'w',
            'full' => $photo->url,
            'width' => $photo->width,
            'height' => $photo->height,
            'alt' => $photo->alt ?: $fallbackAlt,
            'caption' => $photo->caption,
        ];
    }

    /** @return list<array{client_label: string, quote: string}> */
    public function testimonials(int $limit = 6): array
    {
        $rows = Testimonial::query()->published()->ordered()->limit($limit)->get(['client_label', 'quote']);

        if ($rows->isNotEmpty()) {
            return $rows->map(fn (Testimonial $row) => $row->only(['client_label', 'quote']))->all();
        }

        return $this->usesPlaceholders() ? Placeholder::TESTIMONIALS : [];
    }

    // ── SEO ─────────────────────────────────────────────────────────────

    /** Social preview image: the hero when uploaded, else the neutral default. */
    public function ogImage(): array
    {
        $hero = $this->heroImage();

        if ($hero && $this->site->hero_image_path) {
            return ['src' => $hero['src'], 'width' => $hero['width'], 'height' => $hero['height']];
        }

        [$file, $width, $height] = Placeholder::OG_IMAGE;

        return ['src' => asset($file), 'width' => $width, 'height' => $height];
    }

    /**
     * schema.org HomeAndConstructionBusiness (a LocalBusiness) — what lets
     * Google tie the site to "interior Pekanbaru" and to the Business
     * Profile. NAP must equal the Google Business Profile exactly.
     *
     * @return array<string, mixed>
     */
    public function businessJsonLd(): array
    {
        $city = $this->city();
        $hours = $this->openingHoursSpec();

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'HomeAndConstructionBusiness',
            '@id' => route('site.home').'#bisnis',
            'name' => $this->name(),
            'legalName' => $this->legalName(),
            'description' => $this->heroSubheadline(),
            'url' => route('site.home'),
            'logo' => $this->logoUrl(),
            'image' => $this->ogImage()['src'],
            'telephone' => $this->internationalPhone(),
            'email' => $this->email(),
            'address' => array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => $this->address(),
                'addressLocality' => $city,
                'addressRegion' => City::DEFAULTS[$city] ?? null,
                'addressCountry' => 'ID',
            ]),
            'areaServed' => ['@type' => 'City', 'name' => $city],
            'sameAs' => array_values(array_filter([$this->instagramUrl()])) ?: null,
            'openingHours' => $hours,
            'foundingDate' => $this->site->founded_year ? (string) $this->site->founded_year : null,
        ], fn ($value) => $value !== null && $value !== []);
    }

    /** "Senin–Sabtu, 08.00–17.00 WIB" → "Mo-Sa 08:00-17:00"; null when it can't be read. */
    public function openingHoursSpec(): ?string
    {
        $text = mb_strtolower((string) $this->openingHours());
        $days = implode('|', array_map(fn ($day) => preg_quote($day, '/'), array_keys(self::DAYS)));

        if (! preg_match("/({$days})\s*(?:[–\-]|s\.?d\.?|sampai)\s*({$days}).*?(\d{1,2})[.:](\d{2})\s*[–\-]\s*(\d{1,2})[.:](\d{2})/u", $text, $match)) {
            return null;
        }

        return sprintf('%s-%s %02d:%s-%02d:%s', self::DAYS[$match[1]], self::DAYS[$match[2]], $match[3], $match[4], $match[5], $match[6]);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function filled(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** The saved text, or the placeholder while placeholders are on. */
    private function text(?string $value, string $placeholder): ?string
    {
        return $this->filled($value) ?? ($this->usesPlaceholders() ? $placeholder : null);
    }

    /** [width, height] of a stored image, read once per file. */
    private function storedImageSize(string $disk, string $path): ?array
    {
        return Cache::rememberForever('site-image-size:'.md5($disk.$path), function () use ($disk, $path) {
            $storage = Storage::disk($disk);

            if (! $storage->exists($path)) {
                return null;
            }

            $size = @getimagesizefromstring((string) $storage->get($path));

            return $size ? [$size[0], $size[1]] : null;
        });
    }
}
