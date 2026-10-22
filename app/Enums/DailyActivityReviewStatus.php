<?php

namespace App\Enums;

/**
 * Sprint 29 Sub 13 (I2) — HR's verdict on one employee's day. SESUAI and
 * PERLU_KLARIFIKASI are for a day with activities (klarifikasi also for an
 * empty one — "why nothing?"), TIDAK_MENGISI only for an empty working day.
 */
enum DailyActivityReviewStatus: string
{
    case Sesuai = 'SESUAI';
    case PerluKlarifikasi = 'PERLU_KLARIFIKASI';
    case TidakMengisi = 'TIDAK_MENGISI';

    public function label(): string
    {
        return match ($this) {
            self::Sesuai => 'Sesuai',
            self::PerluKlarifikasi => 'Perlu klarifikasi',
            self::TidakMengisi => 'Tidak mengisi',
        };
    }
}
