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
     * (decided 2026-09-28, Sprint 8). Added in Sprint 9 (decision #5):
     * PINDAH_DANA — always two legs, written by FundTransferService (a
     * manual single leg would put money in or out of the company that
     * never moved) — and GAJI_KARYAWAN, now written by the wage flow
     * (StaffPaymentService, "paid" = kategori + reference_id = task) and
     * the payroll module for permanent staff.
     *
     * @return list<self>
     */
    public static function systemManaged(): array
    {
        return [
            self::Pinjaman,
            self::HutangIdeal,
            self::DownPayment,
            self::Termin,
            self::PindahDana,
            self::GajiKaryawan,
            self::PenaltyCollect, // Sprint 9 decision #10 — PenaltyCollectionService only
        ];
    }

    /** Display label — mirrors CATEGORY_OPTIONS in Components/modules/finance/TransactionFormDialog.tsx. */
    public function label(): string
    {
        return match ($this) {
            self::DownPayment => 'Down Payment',
            self::Termin => 'Termin',
            self::Operasional => 'Operasional',
            self::Pinjaman => 'Pinjaman',
            self::BeliBahan => 'Beli Bahan',
            self::Angsuran => 'Angsuran',
            self::GajiKaryawan => 'Gaji Karyawan',
            self::LemburBonus => 'Lembur & Bonus',
            self::Logistik => 'Logistik',
            self::HutangIdeal => 'Hutang Ideal',
            self::Pegangan => 'Pegangan',
            self::JasaDesain => 'Jasa Desain',
            self::Vendor => 'Vendor',
            self::PindahDana => 'Pindah Dana',
            self::Konsumsi => 'Konsumsi',
            self::Consumable => 'Consumable',
            self::PeralatanAset => 'Peralatan/Aset',
            self::Bbm => 'BBM',
            self::Owner => 'Owner',
            self::PenaltyCollect => 'Penalty Collect',
            self::Lainnya => 'Lainnya',
        };
    }
}
