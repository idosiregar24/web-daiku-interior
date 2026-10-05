<?php

namespace App\Enums;

/**
 * PRD §4.2 + daiku_schema.sql `designs.status` (13 states) + Sprint 12
 * decision #16's two locked states before the work starts.
 *
 * A design born from a RAB Jasa Desain (`quotation_id` set) runs
 * MENUNGGU_BAYAR → (Finance verifies the invoice) MENUNGGU_PENUGASAN →
 * (Kepala Desain assigns) DESAIN → (Marketing sends) WAITING_ACC_DESAIN →
 * REVISI_DESAIN (Marketing, counted) ↺ / ACC_DESAIN — and stops there: the
 * RAB, offer and production stages (GAMBAR_RAB … DONE_PRODUKSI) now live
 * on the quotation and project, and are kept for pre-Sprint-12 designs.
 */
enum DesignStatus: string
{
    case MenungguBayar = 'MENUNGGU_BAYAR';
    case MenungguPenugasan = 'MENUNGGU_PENUGASAN';
    case Brief = 'BRIEF';
    case Desain = 'DESAIN';
    case WaitingAccDesain = 'WAITING_ACC_DESAIN';
    case RevisiDesain = 'REVISI_DESAIN';
    case AccDesain = 'ACC_DESAIN';
    case GambarRab = 'GAMBAR_RAB';
    case PembuatanPenawaran = 'PEMBUATAN_PENAWARAN';
    case WaitingAccPenawaran = 'WAITING_ACC_PENAWARAN';
    case Produksi = 'PRODUKSI';
    case RejectProduksi = 'REJECT_PRODUKSI';
    case DoneProduksi = 'DONE_PRODUKSI';
    case HoldClient = 'HOLD_CLIENT';
    case RevisiClient = 'REVISI_CLIENT';

    /** Decision #16 — paid for and assigned yet? Until then nobody works on it. */
    public function isLocked(): bool
    {
        return $this === self::MenungguBayar || $this === self::MenungguPenugasan;
    }

    /** @return list<string> */
    public static function lockedValues(): array
    {
        return [self::MenungguBayar->value, self::MenungguPenugasan->value];
    }
}
