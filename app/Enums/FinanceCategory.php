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

    /**
     * Categories written only by their dedicated flow, which also keeps a
     * balance in sync — loans (StaffLoanService), supplier debts
     * (SupplierDebtService), termin DP/pelunasan (TerminService). A manual
     * transaction in one of these would move cash without touching that
     * balance, so the manual "Catat Transaksi" form rejects them
     * (decided 2026-09-28, Sprint 8). GAJI_KARYAWAN stays manual: it is
     * also how permanent-staff salaries are recorded until that module
     * exists.
     *
     * @return list<self>
     */
    public static function systemManaged(): array
    {
        return [self::Pinjaman, self::HutangIdeal, self::DownPayment, self::Termin];
    }
}
