<?php

namespace App\Support;

/**
 * Material/stock quantities are DECIMAL(12,2) — fractional units are
 * allowed (Sprint 11 decision #3: 2,5 m, 1,5 kg). Arithmetic on them is
 * done in whole hundredths so a run of additions and subtractions never
 * drifts the way float math does (19 − 17 must be exactly 2).
 */
final class Quantity
{
    public static function toHundredths(float|int|string|null $qty): int
    {
        return (int) round((float) $qty * 100);
    }

    public static function fromHundredths(int $hundredths): float
    {
        return $hundredths / 100;
    }

    /** "17", "2,5", "1.250,75" — Indonesian number format, no trailing zeros. */
    public static function format(float|int|string|null $qty): string
    {
        $value = round((float) $qty, 2);

        if (floor($value) === $value) {
            return number_format($value, 0, ',', '.');
        }

        return rtrim(number_format($value, 2, ',', '.'), '0');
    }
}
