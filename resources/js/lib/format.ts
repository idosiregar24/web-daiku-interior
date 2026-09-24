/**
 * Display formatting shared across pages (Rupiah, Indonesian dates).
 * Kept out of lib/utils.ts, which frontend-standards.md §1 reserves for
 * shadcn's cn().
 */

const RUPIAH = new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
});

const RUPIAH_COMPACT = new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 1,
    notation: 'compact',
});

/** "Rp 1.250.000" — accepts Eloquent decimal strings ("1250000.00") as-is. */
export function formatRupiah(value: string | number | null | undefined): string {
    return RUPIAH.format(Number(value ?? 0));
}

/** "Rp 1,3 jt" — for chart axes and dense stat tiles. */
export function formatRupiahCompact(value: string | number | null | undefined): string {
    return RUPIAH_COMPACT.format(Number(value ?? 0));
}

/** "24 Sep 2026" — `value` is an ISO date/datetime string from the API. */
export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
}

/** "24 Sep 2026, 14.05" */
export function formatDateTime(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/** "5 menit lalu" — for notification feeds. */
export function formatRelative(value: string): string {
    const seconds = Math.round((new Date(value).getTime() - Date.now()) / 1000);
    const rtf = new Intl.RelativeTimeFormat('id-ID', { numeric: 'auto' });
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return rtf.format(Math.round(seconds / size), unit);
        }
    }

    return 'baru saja';
}
