import type { ProgressBasis } from '@/types';

/**
 * Sprint 29 Sub 10 (B7) — the project progress names its basis wherever it
 * matters: a project whose RAB items carry a wage reads the Pengawas's
 * pengecekan (progres berbobot, Project::actualProgress()), an older one the
 * daily targets (actual activity). Numbers come from the server only.
 */

export const PROGRESS_BASIS_LABEL: Record<ProgressBasis, string> = {
    weighted: 'Progres berbobot',
    activity: 'Actual activity',
};

export const PROGRESS_BASIS_HINT: Record<ProgressBasis, string> = {
    weighted: 'Dari pengecekan Pengawas per item RAB, ditimbang nilai RAB.',
    activity: 'Dari daily target yang selesai.',
};

/** "67,7%" — Indonesian decimals (a progres berbobot has up to 2), no space like the other % on the page. */
export function formatProgress(value: number | null | undefined): string {
    if (value === null || value === undefined) return '—';

    return `${value.toLocaleString('id-ID', { maximumFractionDigits: 2 })}%`;
}

/** "progres berbobot 67,7%" / "actual activity 80%" — inside a sentence. */
export function progressPhrase(value: number | null | undefined, basis: ProgressBasis | undefined): string {
    return `${PROGRESS_BASIS_LABEL[basis ?? 'activity'].toLowerCase()} ${formatProgress(value ?? 0)}`;
}
