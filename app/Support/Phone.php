<?php

namespace App\Support;

/**
 * Sprint 16 Sub 07 — the one place an Indonesian mobile number is
 * understood: digits only, starting `08`, 10–13 digits long
 * (`081234567890`). Mirrored in `resources/js/lib/phone.ts`.
 */
final class Phone
{
    public const PATTERN = '/^08\d{8,11}$/';

    /**
     * "+62 812-3456-7890" / "62812…" / "0812.3456.7890" / "812…" → "0812…".
     * Anything that isn't digits after stripping separators comes back
     * trimmed but otherwise untouched, so validation can reject it.
     */
    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $stripped = preg_replace('/[\s\-.()]/', '', $value);

        if (! preg_match('/^\+?\d+$/', $stripped)) {
            return $value;
        }

        $digits = ltrim($stripped, '+');

        return match (true) {
            str_starts_with($digits, '62') => '0'.substr($digits, 2),
            str_starts_with($digits, '8') => '0'.$digits,
            default => $digits,
        };
    }

    public static function isValid(?string $value): bool
    {
        return $value !== null && preg_match(self::PATTERN, $value) === 1;
    }

    /** "081234567890" → "0812-3456-7890" (groups of 4 after the "08xx" prefix). */
    public static function format(?string $value): ?string
    {
        if (! self::isValid($value)) {
            return $value;
        }

        return implode('-', str_split($value, 4));
    }

    /** "081234567890" → "6281234567890" for wa.me links; null when not a valid number. */
    public static function whatsapp(?string $value): ?string
    {
        return self::isValid($value) ? '62'.substr($value, 1) : null;
    }
}
