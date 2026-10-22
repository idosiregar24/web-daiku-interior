<?php

namespace App\Enums;

/**
 * Sprint 29 Sub 11 (E4) — where one project material line stands, read in
 * one place (ProjectMaterial::orderStatus()) for the planner, the Material
 * tab, the Pemesanan Bahan card, the Ringkasan and Logistik's queue:
 *
 *   DIRENCANAKAN → DIAJUKAN → DISETUJUI → DIPESAN → SEBAGIAN → LENGKAP
 *                          └→ DITOLAK              (GUDANG: → DARI_GUDANG)
 *
 * MENUNGGU_PM is a Tukang's request before its PM approves. The approval
 * part comes from `request_status`; the rest from `ordered_at` and
 * `qty_received` (DECIMAL compared in hundredths).
 */
enum MaterialOrderStatus: string
{
    case Direncanakan = 'DIRENCANAKAN';
    case MenungguPm = 'MENUNGGU_PM';
    case Diajukan = 'DIAJUKAN';
    case Disetujui = 'DISETUJUI';
    case Ditolak = 'DITOLAK';
    case Dipesan = 'DIPESAN';
    case Sebagian = 'SEBAGIAN';
    case Lengkap = 'LENGKAP';
    case DariGudang = 'DARI_GUDANG';

    public function label(): string
    {
        return match ($this) {
            self::Direncanakan => 'Direncanakan',
            self::MenungguPm => 'Menunggu PM',
            self::Diajukan => 'Diajukan',
            self::Disetujui => 'Disetujui',
            self::Ditolak => 'Ditolak',
            self::Dipesan => 'Dipesan',
            self::Sebagian => 'Diterima Sebagian',
            self::Lengkap => 'Lengkap',
            self::DariGudang => 'Dari Gudang',
        };
    }

    /** Everything the planned amount arrived for — from a purchase or the warehouse. */
    public function isComplete(): bool
    {
        return $this === self::Lengkap || $this === self::DariGudang;
    }
}
