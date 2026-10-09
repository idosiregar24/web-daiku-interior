<?php

namespace App\Support\CompanyProfile;

use App\Enums\ProjectType;
use Illuminate\Support\Str;

/**
 * Sprint 20 Sub 06 (K4) — the services of the company profile, defined
 * once: which project types get a `/layanan/{slug}` page, under which
 * name and slug. Every ProjectType except LAINNYA; TOKO and RETAIL_TOKO
 * share one page. A slug is the keyword plus the home city
 * (`kitchen-set-pekanbaru`) and is never edited from the UI — a renamed
 * slug would throw away whatever Google already learned about the page.
 */
final class ServiceCatalog
{
    /** Primary type => [slug keyword, name override, merged types]. Order = order on the site. */
    private const SERVICES = [
        'KITCHEN_SET' => ['kitchen-set', null, []],
        'KAMAR_SET' => ['kamar-set', null, []],
        'RUANG_TAMU_TV' => ['interior-ruang-tamu', null, []],
        'CAFE' => ['interior-cafe', null, []],
        'TOKO' => ['interior-toko', 'Toko & Retail', ['RETAIL_TOKO']],
        'KANTOR' => ['interior-kantor', null, []],
        'RENOVASI' => ['renovasi-rumah', null, []],
        'ARSITEKTURAL' => ['desain-arsitektur', null, []],
    ];

    /** @var list<ServiceEntry>|null */
    private static ?array $entries = null;

    /** @return list<ServiceEntry> */
    public static function all(): array
    {
        return self::$entries ??= array_map(function (string $type) {
            [$keyword, $name, $merged] = self::SERVICES[$type];
            $primary = ProjectType::from($type);

            return new ServiceEntry(
                type: $primary,
                types: [$primary, ...array_map(fn (string $value) => ProjectType::from($value), $merged)],
                slug: $keyword.'-'.Str::slug(self::city()),
                name: $name ?? $primary->label(),
                keyword: Str::title(str_replace('-', ' ', $keyword)),
            );
        }, array_keys(self::SERVICES));
    }

    public static function findBySlug(string $slug): ?ServiceEntry
    {
        foreach (self::all() as $entry) {
            if ($entry->slug === $slug) {
                return $entry;
            }
        }

        return null;
    }

    /** The service a project type belongs to; null for LAINNYA. */
    public static function forType(ProjectType $type): ?ServiceEntry
    {
        foreach (self::all() as $entry) {
            if ($entry->covers($type)) {
                return $entry;
            }
        }

        return null;
    }

    /** The company's own city — every service slug and H1 is about it. */
    public static function city(): string
    {
        return (string) config('daiku.home_city');
    }

    /** Tests switch the home city; drop the memoized slugs. */
    public static function flush(): void
    {
        self::$entries = null;
    }
}
