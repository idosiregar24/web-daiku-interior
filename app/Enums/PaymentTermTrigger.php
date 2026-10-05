<?php

namespace App\Enums;

/**
 * Sprint 12 decision #12 — what makes a payment row due. DI_MUKA (upfront,
 * on the client's approval — D4's "100% di depan" and a project DP) is
 * added to the plan's TANGGAL / MILESTONE / PROYEK_SELESAI.
 */
enum PaymentTermTrigger: string
{
    case DiMuka = 'DI_MUKA';
    case Tanggal = 'TANGGAL';
    case Milestone = 'MILESTONE';
    case ProyekSelesai = 'PROYEK_SELESAI';

    public function label(): string
    {
        return match ($this) {
            self::DiMuka => 'Di muka (saat disetujui)',
            self::Tanggal => 'Tanggal tertentu',
            self::Milestone => 'Milestone selesai',
            self::ProyekSelesai => 'Proyek selesai',
        };
    }
}
