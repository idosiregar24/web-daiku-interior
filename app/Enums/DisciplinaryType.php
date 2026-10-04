<?php

namespace App\Enums;

/**
 * SDM (Sprint 10, §3.1) — disciplinary record kinds. SP1→SP2→SP3 escalate
 * (decision #12, DisciplineService); TEGURAN_LISAN and CATATAN don't.
 * PEMBATALAN cancels an earlier record through `voids_id` (append-only
 * correction — the cancelled row stays, it just stops counting).
 */
enum DisciplinaryType: string
{
    case TeguranLisan = 'TEGURAN_LISAN';
    case Sp1 = 'SP1';
    case Sp2 = 'SP2';
    case Sp3 = 'SP3';
    case Catatan = 'CATATAN';
    case Pembatalan = 'PEMBATALAN';

    /** Warning-letter level (1–3), or null for non-SP types. */
    public function spLevel(): ?int
    {
        return match ($this) {
            self::Sp1 => 1,
            self::Sp2 => 2,
            self::Sp3 => 3,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::TeguranLisan => 'Teguran Lisan',
            self::Sp1 => 'SP1',
            self::Sp2 => 'SP2',
            self::Sp3 => 'SP3',
            self::Catatan => 'Catatan',
            self::Pembatalan => 'Pembatalan',
        };
    }
}
