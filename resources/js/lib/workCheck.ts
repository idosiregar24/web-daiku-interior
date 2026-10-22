import type { WageShare, WorkCheck } from '@/types';

/**
 * Sprint 29 Sub 09 — the live preview of the pengecekan form. Mirrors
 * WorkItemService (the server computes the authoritative numbers when the
 * Pengawas sends it):
 *
 *   progres berbobot = Σ (bobot_i × progres_i) ÷ 100          (bobot from the RAB value)
 *   upah boleh cair  = Σ (subtotal upah_i × progres_i ÷ 100)
 *   cair kali ini    = upah boleh cair − sudah cair (≥ 0, ≤ sisa total upah)
 *
 * Percentages in hundredths, money in cents — like the server.
 */

/** "12,5" / "12.5" → 12.5; empty or invalid → 0. */
export function parsePercent(value: string | number | null | undefined): number {
    if (typeof value === 'number') return Number.isFinite(value) ? value : 0;
    const parsed = Number(
        String(value ?? '')
            .trim()
            .replace(',', '.'),
    );

    return Number.isFinite(parsed) ? parsed : 0;
}

const hundredths = (percent: number) => Math.round(percent * 100);
const cents = (rupiah: number) => Math.round(rupiah * 100);

interface Sums {
    weighted_progress: number | null;
    payable: number;
    total_wage: number;
}

function sums(items: WorkCheck['items'], progress: Record<number, number>): Sums {
    const rab = items.reduce((sum, item) => sum + cents(item.rab_subtotal), 0);
    const weighted = items.reduce((sum, item) => sum + cents(item.rab_subtotal) * (progress[item.id] ?? 0), 0);
    const payableCents = items.reduce((sum, item) => sum + (cents(item.wage_subtotal) * (progress[item.id] ?? 0)) / 10000, 0);

    return {
        weighted_progress: items.length === 0 ? null : rab > 0 ? Math.round(weighted / rab) / 100 : 0,
        payable: Math.round(payableCents / 100),
        total_wage: items.reduce((sum, item) => sum + cents(item.wage_subtotal), 0) / 100,
    };
}

/** Split an amount (whole rupiah) by weights — largest remainder, like WorkItemService::distribute(). */
export function distribute(amount: number, weights: Record<string, number>): WageShare[] {
    const total = Object.values(weights).reduce((sum, weight) => sum + weight, 0);
    const rupiah = Math.round(amount);

    if (total <= 0 || rupiah <= 0) return [];

    const raw = Object.entries(weights).map(([label, weight]) => ({
        label,
        value: (rupiah * weight) / total,
    }));
    const parts = raw.map((row) => ({
        label: row.label,
        amount: Math.floor(row.value),
        rest: row.value - Math.floor(row.value),
    }));
    let left = rupiah - parts.reduce((sum, row) => sum + row.amount, 0);

    [...parts]
        .sort((a, b) => b.rest - a.rest)
        .forEach((row) => {
            if (left-- > 0) row.amount++;
        });

    return parts
        .filter((row) => row.amount > 0)
        .sort((a, b) => b.amount - a.amount)
        .map(({ label, amount: value }) => ({ label, amount: value }));
}

export interface WorkCheckPreview {
    /** New progress per item, %. */
    progress: Record<number, number>;
    weightedProgress: number | null;
    payable: number;
    /** Null for viewers without the payments. */
    payableNow: number | null;
    teams: {
        label: string;
        total_wage: number;
        progress: number | null;
        payable: number;
    }[];
    shares: WageShare[];
}

/** The numbers of the pengecekan if `added` (% per item id) is sent now. */
export function previewWorkCheck(check: WorkCheck, added: Record<number, number>): WorkCheckPreview {
    const progress: Record<number, number> = {};
    const progressHundredths: Record<number, number> = {};

    check.items.forEach((item) => {
        const value = Math.min(10000, Math.max(0, hundredths(item.progress) + hundredths(added[item.id] ?? 0)));
        progressHundredths[item.id] = value;
        progress[item.id] = value / 100;
    });

    const all = sums(check.items, progressHundredths);
    const groups = new Map<string, WorkCheck['items']>();
    check.items.forEach((item) => groups.set(item.team_lead, [...(groups.get(item.team_lead) ?? []), item]));
    const teams = [...groups.entries()]
        .map(([label, rows]) => {
            const team = sums(rows, progressHundredths);

            return {
                label,
                total_wage: team.total_wage,
                progress: team.weighted_progress,
                payable: team.payable,
            };
        })
        .sort((a, b) => b.total_wage - a.total_wage);

    const { already_paid: alreadyPaid, total_wage: totalWage } = check.summary;
    const payableNow = alreadyPaid === null ? null : Math.max(0, Math.min(all.payable - alreadyPaid, totalWage - alreadyPaid));

    // WorkItemService::teamShares('progress') — what each team may draw now.
    let weights: Record<string, number> = Object.fromEntries(
        teams.map((team) => [team.label, Math.max(0, team.payable - ((alreadyPaid ?? 0) * team.total_wage) / (totalWage || 1))]),
    );
    if (Object.values(weights).every((weight) => weight <= 0)) {
        weights = Object.fromEntries(teams.map((team) => [team.label, team.total_wage]));
    }

    return {
        progress,
        weightedProgress: all.weighted_progress,
        payable: all.payable,
        payableNow,
        teams,
        shares: payableNow ? distribute(payableNow, weights) : [],
    };
}

/** "69,48 %" */
export function formatPercent(value: number | null | undefined): string {
    if (value === null || value === undefined) return '—';

    return `${value.toLocaleString('id-ID', { maximumFractionDigits: 2 })} %`;
}
