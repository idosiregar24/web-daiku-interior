/**
 * Shared bits of the division dashboards (KPI Desain, Dashboard Quotation,
 * Monitor Proyek, Dashboard QA).
 */

/**
 * A DataTable inside a `flush` SectionCard: drop TableCard's own border,
 * radius and shadow so the table runs edge to edge under the card header
 * instead of nesting a second card.
 */
export const FLUSH_TABLE_CLASS = 'rounded-none border-0 bg-transparent shadow-none';

/** "Terlambat 3 hari" — a count of days past a deadline (server-computed, ≥ 0). */
export function lateLabel(days: number): string {
    return days <= 0 ? 'Jatuh tempo hari ini' : `Terlambat ${days} hari`;
}

/** Signed days until a deadline: "Hari ini", "Besok", "3 hari lagi", "Lewat 2 hari". */
export function dueLabel(daysLeft: number): string {
    if (daysLeft === 0) return 'Hari ini';
    if (daysLeft === 1) return 'Besok';
    if (daysLeft > 1) return `${daysLeft} hari lagi`;

    return `Lewat ${Math.abs(daysLeft)} hari`;
}

/** "Menunggu 4 hari" / "Hari ini". */
export function waitingLabel(days: number): string {
    return days <= 0 ? 'Masuk hari ini' : `Menunggu ${days} hari`;
}

/** Review/turnaround duration: under a day in hours, otherwise days with one decimal. */
export function formatDuration(hours: number | null): string {
    if (hours === null) return '—';
    if (hours < 24) return `${Math.round(hours)} jam`;

    return `${(hours / 24).toLocaleString('id-ID', { maximumFractionDigits: 1 })} hari`;
}
