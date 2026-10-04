import type { DisciplinaryRecord, DisciplinaryType, Position } from '@/types';

/**
 * One disciplinary record as DisciplineService::present() sends it — the
 * base `DisciplinaryRecord` plus the cancelling entry (`void`) and, on a
 * PEMBATALAN row, the record it cancels (`voids`).
 */
export interface DisciplineRecordRow extends Omit<DisciplinaryRecord, 'employee' | 'recorder'> {
    recorder: { id: number; name: string } | null;
    is_voided: boolean;
    is_active_sp: boolean;
    employee?: {
        id: number;
        name: string;
        is_active: boolean;
        position: (Pick<Position, 'id' | 'name' | 'division_id'> & { division: { id: number; name: string } | null }) | null;
    } | null;
    voids?: { id: number; type: DisciplinaryType; issued_on: string } | null;
    void?: { id: number; reason: string; issued_on: string; recorder: string | null } | null;
}

/** An SP in force today. */
export interface ActiveSp {
    type: DisciplinaryType;
    valid_until: string;
}

/** An employee the "Catat" dialog can pick, with the only SP level allowed next (decision #12). */
export interface DisciplineEmployeeOption {
    id: number;
    name: string;
    position?: string | null;
    division?: string | null;
    active_sp: ActiveSp | null;
    /** null = SP3 is in force (last level). */
    next_sp: DisciplinaryType | null;
}

export const DISCIPLINE_TYPE_LABEL: Record<DisciplinaryType, string> = {
    TEGURAN_LISAN: 'Teguran Lisan',
    SP1: 'SP1',
    SP2: 'SP2',
    SP3: 'SP3',
    CATATAN: 'Catatan',
    PEMBATALAN: 'Pembatalan',
};

export const SP_TYPES: DisciplinaryType[] = ['SP1', 'SP2', 'SP3'];

export function isSpType(type: DisciplinaryType): boolean {
    return SP_TYPES.includes(type);
}
