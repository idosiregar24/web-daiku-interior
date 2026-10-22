import { formatQty } from '@/lib/format';
import type { MaterialOrderStatus, ProjectMaterial } from '@/types';
import { format, parseISO } from 'date-fns';

/** Sprint 27 P3 — where a project's material line stands after Start Produksi. */
export type MaterialArrival = 'complete' | 'partial' | 'waiting' | 'request';

/** What `materialStatus()` needs — a `ProjectMaterial` or a TM-card planner line. */
export interface MaterialStatusLine {
    order_status: MaterialOrderStatus;
    reject_reason?: string | null;
    expected_at?: string | null;
    qty_received: number;
    qty_planned: number;
}

export interface MaterialStatus {
    status: MaterialOrderStatus;
    /** The part after the chip: "tiba 24/10", "3/5 lbr", the rejection reason — or null. */
    detail: string | null;
    /** A rejection reads in the error ink, the rest muted. */
    tone: 'error' | 'muted';
}

/**
 * Sprint 29 Sub 11 (E4) — the one wording of where a line stands, for the
 * planner, the Material tab, Pemesanan Bahan, the Eksekusi card and the
 * Ringkasan. The status itself is the server's (`order_status` =
 * ProjectMaterial::orderStatus()): Direncanakan · Diajukan · Disetujui ·
 * Ditolak (alasan) · Dipesan (perkiraan tiba dd/mm) · Diterima sebagian
 * (x/y) · Lengkap · Dari gudang. Shown as `<MaterialStatusChip>`.
 */
export function materialStatus(line: MaterialStatusLine, unit?: string | null): MaterialStatus {
    const status = line.order_status;
    let detail: string | null = null;

    if (status === 'DITOLAK') detail = line.reject_reason ?? null;
    if (status === 'DIPESAN' && line.expected_at) detail = `perkiraan tiba ${format(parseISO(line.expected_at), 'dd/MM')}`;
    if (status === 'SEBAGIAN') detail = `${formatQty(line.qty_received)}/${formatQty(line.qty_planned)}${unit ? ` ${unit}` : ''}`;

    return { status, detail, tone: status === 'DITOLAK' ? 'error' : 'muted' };
}

/**
 * One reading for the Eksekusi "Pemesanan Bahan" card and the Ringkasan,
 * from `materialStatus()`: complete once what arrived (issued from the
 * warehouse or bought) reaches the plan, partial when some arrived, waiting
 * when nothing did (approved or ordered). A line not decided yet — a draft
 * of the Rencana Bahan, a request at the PM / Logistik — is `request`; a
 * rejected one is left out (null).
 */
export function materialArrival(line: ProjectMaterial): MaterialArrival | null {
    switch (materialStatus(line).status) {
        case 'DITOLAK':
            return null;
        case 'DIRENCANAKAN':
        case 'MENUNGGU_PM':
        case 'DIAJUKAN':
            return 'request';
        case 'SEBAGIAN':
            return 'partial';
        case 'LENGKAP':
        case 'DARI_GUDANG':
            return 'complete';
        default:
            return 'waiting';
    }
}

export interface MaterialArrivalSummary {
    total: number;
    complete: number;
    partial: number;
    waiting: number;
    request: number;
    /** Sprint 29 Sub 11 — of `waiting`, the lines Logistik already ordered ("Dipesan"). */
    ordered: number;
    /** Approved lines not complete yet, oldest first. */
    open: ProjectMaterial[];
}

export function summarizeArrivals(lines: ProjectMaterial[]): MaterialArrivalSummary {
    const summary: MaterialArrivalSummary = { total: 0, complete: 0, partial: 0, waiting: 0, request: 0, ordered: 0, open: [] };

    for (const line of lines) {
        const state = materialArrival(line);
        if (state === null) continue;
        summary.total += 1;
        summary[state] += 1;
        if (line.order_status === 'DIPESAN') summary.ordered += 1;
        if (state === 'partial' || state === 'waiting') summary.open.push(line);
    }

    return summary;
}
