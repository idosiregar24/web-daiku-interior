<?php

namespace App\Enums;

/**
 * Sprint 12 decision #3 — a site survey's state. Inside Pekanbaru:
 * DIJADWALKAN → SELESAI. Outside: MENUNGGU_BAYAR → (Finance verifies the
 * RAB Jasa Survey invoice, Sub 6) SIAP → SELESAI. Any open one → BATAL
 * (reason required).
 */
enum LeadSurveyStatus: string
{
    case Dijadwalkan = 'DIJADWALKAN';
    case MenungguBayar = 'MENUNGGU_BAYAR';
    case Siap = 'SIAP';
    case Selesai = 'SELESAI';
    case Batal = 'BATAL';

    /** Not finished and not cancelled — can still be edited or cancelled. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Dijadwalkan, self::MenungguBayar, self::Siap], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Dijadwalkan => 'Dijadwalkan',
            self::MenungguBayar => 'Menunggu Pembayaran',
            self::Siap => 'Siap Berangkat',
            self::Selesai => 'Selesai',
            self::Batal => 'Batal',
        };
    }
}
