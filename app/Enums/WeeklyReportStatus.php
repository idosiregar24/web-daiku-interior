<?php

namespace App\Enums;

/** Sprint 24 D10 — the Pengawas's Friday report: DIAJUKAN → DISETUJUI (PM) / DITOLAK (PM, note; re-sent as DIAJUKAN). */
enum WeeklyReportStatus: string
{
    case Diajukan = 'DIAJUKAN';
    case Disetujui = 'DISETUJUI';
    case Ditolak = 'DITOLAK';
}
