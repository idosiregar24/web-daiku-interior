import type { ProjectMaterial } from '@/types';

/** Sprint 27 P3 — where a project's material line stands after Start Produksi. */
export type MaterialArrival = 'complete' | 'partial' | 'waiting' | 'request';

/** DECIMAL(12,2) quantities compared in hundredths (golden rule 10), never as raw floats. */
const hundredths = (value: number) => Math.round(Number(value) * 100);

/**
 * One reading for the Eksekusi "Pemesanan Bahan" card and the Ringkasan:
 * an approved line is complete once what arrived (issued from the
 * warehouse or bought — `qty_received`) reaches the plan, partial when
 * some arrived, waiting when nothing did. A request still waiting for the
 * PM / Logistik is `request`; a rejected one is left out (null).
 */
export function materialArrival(line: ProjectMaterial): MaterialArrival | null {
    if (line.request_status === 'DITOLAK') return null;
    if (line.request_status !== 'DISETUJUI') return 'request';

    const received = hundredths(line.qty_received);
    const planned = hundredths(line.qty_planned);

    if (received <= 0) return 'waiting';

    return planned <= 0 || received >= planned ? 'complete' : 'partial';
}

export interface MaterialArrivalSummary {
    total: number;
    complete: number;
    partial: number;
    waiting: number;
    request: number;
    /** Approved lines not complete yet, oldest first. */
    open: ProjectMaterial[];
}

export function summarizeArrivals(lines: ProjectMaterial[]): MaterialArrivalSummary {
    const summary: MaterialArrivalSummary = { total: 0, complete: 0, partial: 0, waiting: 0, request: 0, open: [] };

    for (const line of lines) {
        const state = materialArrival(line);
        if (state === null) continue;
        summary.total += 1;
        summary[state] += 1;
        if (state === 'partial' || state === 'waiting') summary.open.push(line);
    }

    return summary;
}
