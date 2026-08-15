<?php

namespace App\Enums;

/** PRD §4.2 + daiku_schema.sql `designs.status` (13 states). */
enum DesignStatus: string
{
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
}
