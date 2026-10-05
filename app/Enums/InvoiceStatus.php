<?php

namespace App\Enums;

/**
 * Sprint 12 decision #21: DITERBITKAN (Marketing issued it) →
 * MENUNGGU_VERIFIKASI (payment proof attached) → TERVERIFIKASI (Finance
 * confirmed the money came in). Finance rejecting the proof sends it back
 * to DITERBITKAN with `reject_reason` — no separate rejected state.
 */
enum InvoiceStatus: string
{
    case Diterbitkan = 'DITERBITKAN';
    case MenungguVerifikasi = 'MENUNGGU_VERIFIKASI';
    case Terverifikasi = 'TERVERIFIKASI';

    public function label(): string
    {
        return match ($this) {
            self::Diterbitkan => 'Diterbitkan',
            self::MenungguVerifikasi => 'Menunggu Verifikasi',
            self::Terverifikasi => 'Terverifikasi',
        };
    }
}
