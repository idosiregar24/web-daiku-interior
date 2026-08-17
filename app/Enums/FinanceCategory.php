<?php

namespace App\Enums;

/** PRD §4.7 "Kategori Transaksi Lengkap" + daiku_schema.sql `finance_transactions.kategori`. */
enum FinanceCategory: string
{
    case DownPayment = 'DOWN_PAYMENT';
    case Termin = 'TERMIN';
    case Operasional = 'OPERASIONAL';
    case Pinjaman = 'PINJAMAN';
    case BeliBahan = 'BELI_BAHAN';
    case Angsuran = 'ANGSURAN';
    case GajiKaryawan = 'GAJI_KARYAWAN';
    case LemburBonus = 'LEMBUR_BONUS';
    case Logistik = 'LOGISTIK';
    case HutangIdeal = 'HUTANG_IDEAL';
    case Pegangan = 'PEGANGAN';
    case JasaDesain = 'JASA_DESAIN';
    case Vendor = 'VENDOR';
    case PindahDana = 'PINDAH_DANA';
    case Konsumsi = 'KONSUMSI';
    case Consumable = 'CONSUMABLE';
    case PeralatanAset = 'PERALATAN_ASET';
    case Bbm = 'BBM';
    case Owner = 'OWNER';
    case PenaltyCollect = 'PENALTY_COLLECT';
    case Lainnya = 'LAINNYA';
}
