import { z } from 'zod';

const TWO_DECIMALS = /^\d+(\.\d{1,2})?$/;

/** "2,5" or "2.5" → 2.5 */
export function parseQty(value: string): number {
    return Number(value.trim().replace(',', '.'));
}

/**
 * A quantity form field (kept as a string for the input). Mirrors the
 * backend's `numeric|decimal:0,2|min:…` rules — fractional quantities are
 * allowed since Sprint 11 (2,5 m, 1,5 kg).
 */
export function quantityField(label: string, min = 0.01) {
    return z
        .string()
        .trim()
        .min(1, `${label} wajib diisi`)
        .refine((v) => TWO_DECIMALS.test(v.replace(',', '.')), `${label} harus angka, maksimal 2 angka di belakang koma`)
        .refine((v) => parseQty(v) >= min, `${label} minimal ${String(min).replace('.', ',')}`);
}

/**
 * Sprint 23 K5 — a quantity/dimension string in whole hundredths
 * (DECIMAL(12,2), like App\Support\Quantity), or null when empty or not a
 * number. Arithmetic on volumes goes through this, never raw floats.
 */
export function toHundredths(value: string | number | null | undefined): number | null {
    if (value === null || value === undefined) {
        return null;
    }

    const text = String(value).trim().replace(',', '.');

    if (text === '' || Number.isNaN(Number(text))) {
        return null;
    }

    return Math.round(Number(text) * 100);
}

/** 150 → "1.5", 1234 → "12.34", 300 → "3" — the plain string a quantity field holds. */
export function fromHundredths(hundredths: number): string {
    const whole = Math.trunc(hundredths / 100);
    const fraction = Math.abs(hundredths % 100);

    return fraction === 0 ? String(whole) : `${whole}.${String(fraction).padStart(2, '0').replace(/0$/, '')}`;
}
