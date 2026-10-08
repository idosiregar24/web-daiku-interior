<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Sprint 21 (K5) — the one place a login username is understood: lowercase
 * letters and digits only, starting with a letter, 3–30 characters
 * (`budi`, `budisantoso`, `budi2`). No spaces, dots, underscores or other
 * symbols. Mirrored in `resources/js/lib/username.ts` — change both.
 */
final class Username
{
    public const PATTERN = '/^[a-z][a-z0-9]{2,29}$/';

    public const MIN = 3;

    public const MAX = 30;

    /** Names nobody may take — they read as the system or a whole role. */
    public const RESERVED = [
        'admin', 'administrator', 'superadmin', 'root', 'daiku', 'daikuinterior', 'system', 'sistem', 'support',
        'ceo', 'marketing', 'designer', 'desainer', 'arsitek', 'estimator', 'pm', 'asistenpm', 'kepaladesain',
        'qa', 'finance', 'logistics', 'logistik', 'fieldstaff', 'tukang', 'hr', 'sdm',
    ];

    /** "  Budi " → "budi"; an empty value → null. Never repairs invalid characters — validation rejects those. */
    public static function normalize(?string $value): ?string
    {
        $value = mb_strtolower(trim((string) $value));

        return $value === '' ? null : $value;
    }

    public static function isValid(?string $value): bool
    {
        return $value !== null && preg_match(self::PATTERN, $value) === 1;
    }

    public static function isReserved(?string $value): bool
    {
        return in_array(self::normalize($value), self::RESERVED, true);
    }

    /** Validation rules for the format only — presence and uniqueness are the caller's. */
    public static function rules(): array
    {
        return ['string', 'max:'.self::MAX, 'regex:'.self::PATTERN];
    }

    /**
     * Free usernames built from a person's name — "Budi Santoso" →
     * `budisantoso`, `budis`, `budi`, then `budi2`, `budi3`… when those are
     * taken. Accented letters are transliterated, anything else is dropped.
     *
     * @return list<string>
     */
    public static function suggestFor(string $name, int $limit = 3, ?int $ignoreUserId = null): array
    {
        $words = array_values(array_filter(array_map(
            fn (string $word) => preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($word))),
            preg_split('/\s+/', trim($name)) ?: [],
        )));
        // A username must start with a letter.
        $words[0] = ltrim($words[0] ?? '', '0123456789');
        $words = array_values(array_filter($words));

        if ($words === []) {
            return [];
        }

        $first = $words[0];
        $bases = [implode('', $words)];

        if (count($words) > 1) {
            $bases[] = $first.$words[count($words) - 1][0];
        }

        $bases[] = $first;

        $candidates = [];

        foreach (array_unique($bases) as $base) {
            $base = substr($base, 0, self::MAX);
            $candidates[] = strlen($base) < self::MIN ? str_pad($base, self::MIN, '1') : $base;
        }

        $taken = User::query()
            ->when($ignoreUserId, fn ($query) => $query->whereKeyNot($ignoreUserId))
            ->where(function ($query) use ($candidates, $first) {
                $query->whereIn('username', $candidates)->orWhere('username', 'like', substr($first, 0, self::MAX - 3).'%');
            })
            ->pluck('username')
            ->all();

        $free = fn (string $candidate) => self::isValid($candidate) && ! self::isReserved($candidate) && ! in_array($candidate, $taken, true);

        $suggestions = array_values(array_filter(array_unique($candidates), $free));

        $stem = substr($first, 0, self::MAX - 3);

        for ($number = 2; count($suggestions) < $limit && $number < 1000; $number++) {
            $candidate = strlen($stem) < self::MIN - 1 ? str_pad($stem, self::MIN - 1, '1').$number : $stem.$number;

            if ($free($candidate) && ! in_array($candidate, $suggestions, true)) {
                $suggestions[] = $candidate;
            }
        }

        return array_slice($suggestions, 0, $limit);
    }
}
