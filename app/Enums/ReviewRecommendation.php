<?php

namespace App\Enums;

/**
 * SDM (Sprint 10, §3.4, decision #8) — advice only, never applied to pay
 * automatically. NAIK_GAJI can be turned into a salary-change request,
 * which still needs the CEO's approval.
 */
enum ReviewRecommendation: string
{
    case NaikGaji = 'NAIK_GAJI';
    case Bonus = 'BONUS';
    case Pembinaan = 'PEMBINAAN';
    case Sp = 'SP';
    case TidakAda = 'TIDAK_ADA';
}
