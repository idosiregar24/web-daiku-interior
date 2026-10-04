<?php

namespace App\Enums;

/**
 * Sprint 11 — approval state of a project material line. Lines picked
 * from the catalog are DISETUJUI from the start. Out-of-catalog requests
 * start DIAJUKAN (PM/Estimator) or MENUNGGU_PM (Tukang — the project's PM
 * approves before Logistics sees it, Sub 4) until Logistics decides. Only
 * DISETUJUI lines can be received, used or settled.
 */
enum MaterialRequestStatus: string
{
    case MenungguPm = 'MENUNGGU_PM';
    case Diajukan = 'DIAJUKAN';
    case Disetujui = 'DISETUJUI';
    case Ditolak = 'DITOLAK';

    /** Still waiting for someone's decision — keeps the project from COMPLETED. */
    public function isPending(): bool
    {
        return $this === self::MenungguPm || $this === self::Diajukan;
    }

    public function label(): string
    {
        return match ($this) {
            self::MenungguPm => 'Menunggu PM',
            self::Diajukan => 'Menunggu Logistik',
            self::Disetujui => 'Disetujui',
            self::Ditolak => 'Ditolak',
        };
    }
}
