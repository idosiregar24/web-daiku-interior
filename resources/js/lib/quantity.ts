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
