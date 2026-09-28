import type { StaffLoan } from '@/types';

export type StaffLoanStatus = 'LUNAS' | 'BERJALAN';

/** `LUNAS` once nothing remains, else `BERJALAN` — mirrors StaffLoan::getStatusAttribute() (PHP). */
export function staffLoanStatus(loan: Pick<StaffLoan, 'remaining'>): StaffLoanStatus {
    return Number(loan.remaining) <= 0 ? 'LUNAS' : 'BERJALAN';
}

export const STAFF_LOAN_STATUS_LABEL: Record<StaffLoanStatus, string> = {
    LUNAS: 'Lunas',
    BERJALAN: 'Berjalan',
};
