<?php

namespace App\Enums;

/**
 * Sprint 24 D4 — DIAJUKAN (Pengawas asked) → DISETUJUI_PM (in Finance's
 * queue) → DIBAYAR, or DITOLAK by the PM (a new request may follow).
 */
enum SupervisorPaymentStatus: string
{
    case Diajukan = 'DIAJUKAN';
    case DisetujuiPm = 'DISETUJUI_PM';
    case Ditolak = 'DITOLAK';
    case Dibayar = 'DIBAYAR';

    /** Counts against the fee's total (everything not rejected). */
    public static function committedValues(): array
    {
        return [self::Diajukan->value, self::DisetujuiPm->value, self::Dibayar->value];
    }
}
