import type { Asset, AssetInstallmentStatus } from '@/types';

export const ASSET_INSTALLMENT_STATUS_LABELS: Record<AssetInstallmentStatus, string> = {
    BERJALAN: 'Berjalan',
    JATUH_TEMPO: 'Jatuh Tempo',
    LUNAS: 'Lunas',
};

/** Paid share of the plan, 0–100 (for ProgressBar). */
export function installmentProgress(asset: Pick<Asset, 'total_install' | 'paid_install'>): number {
    const total = Number(asset.total_install ?? 0);

    return total > 0 ? Math.round((Number(asset.paid_install) / total) * 100) : 0;
}

/**
 * What the payment dialog prefills: the planned per-payment amount capped
 * at what is left, or everything left when no amount is planned.
 */
export function suggestedInstallment(asset: Pick<Asset, 'installment_amount' | 'remaining_install'>): number {
    const remaining = Number(asset.remaining_install ?? 0);

    return asset.installment_amount === null ? remaining : Math.min(Number(asset.installment_amount), remaining);
}
