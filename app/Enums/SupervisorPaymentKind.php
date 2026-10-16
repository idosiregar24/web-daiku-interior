<?php

namespace App\Enums;

/** Sprint 24 D4 — what a Jasa Pengawas payment is for. Mirrors `SupervisorPaymentKind` in types/index.d.ts. */
enum SupervisorPaymentKind: string
{
    case Dp = 'DP';
    case Mingguan = 'MINGGUAN';
    case Pelunasan = 'PELUNASAN';

    public function label(): string
    {
        return match ($this) {
            self::Dp => 'DP',
            self::Mingguan => 'Mingguan',
            self::Pelunasan => 'Pelunasan',
        };
    }
}
