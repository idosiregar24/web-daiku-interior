import type { Position, SalaryChange } from '@/types';

/** One salary change as SalaryChangeService::present() sends it. */
export interface SalaryChangeRow extends Omit<SalaryChange, 'employee' | 'requester' | 'decider'> {
    /** When the new salary reached `employees.base_salary`; null on an APPROVED row = waiting for its effective date. */
    applied_at: string | null;
    requester: { id: number; name: string } | null;
    decider: { id: number; name: string } | null;
    employee?: {
        id: number;
        name: string;
        position: (Pick<Position, 'id' | 'name' | 'division_id'> & { division: { id: number; name: string } | null }) | null;
    } | null;
}

/** An employee the "Ajukan Perubahan Gaji" dialog can pick. */
export interface SalaryEmployeeOption {
    id: number;
    name: string;
    base_salary: string;
    position?: string | null;
    division?: string | null;
    /** A pending request, or an approved one still waiting for its effective date. */
    has_open_request: boolean;
}

/** Status chip props for a change — APPROVED but not yet applied shows as "Terjadwal". */
export function salaryChangeChip(change: Pick<SalaryChangeRow, 'status' | 'applied_at'>): { status: string; label: string; tone?: 'info' } {
    if (change.status === 'APPROVED' && !change.applied_at) {
        return { status: 'SCHEDULED', label: 'Terjadwal', tone: 'info' };
    }

    return {
        status: change.status,
        label: { PENDING: 'Menunggu CEO', APPROVED: 'Disetujui', REJECTED: 'Ditolak' }[change.status],
    };
}

/** "+10,0%" change from old to new salary. */
export function salaryDelta(oldSalary: string | number, newSalary: string | number): string {
    const before = Number(oldSalary);
    const after = Number(newSalary);

    if (!before) return '';

    const pct = ((after - before) / before) * 100;

    return `${pct > 0 ? '+' : ''}${pct.toLocaleString('id-ID', { maximumFractionDigits: 1, minimumFractionDigits: 1 })}%`;
}
