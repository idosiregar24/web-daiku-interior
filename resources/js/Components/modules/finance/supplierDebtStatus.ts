import type { SupplierDebt } from '@/types';

/** Derived server-side (App\Models\SupplierDebt::status()), not a DB column — StatusChip already maps the keys. */
export type SupplierDebtDisplayStatus = 'BERJALAN' | 'JATUH_TEMPO' | 'LUNAS';

/** SupplierDebt as serialized by the model, including its appended `status`. */
export type SupplierDebtWithStatus = SupplierDebt & { status: SupplierDebtDisplayStatus };

export const SUPPLIER_DEBT_STATUS_LABELS: Record<SupplierDebtDisplayStatus, string> = {
    BERJALAN: 'Berjalan',
    JATUH_TEMPO: 'Jatuh Tempo',
    LUNAS: 'Lunas',
};
