<?php

namespace App\Enums;

use App\Enums\FinanceCategory as Category;

/** Sprint 12 decision #20 — what an invoice bills (Marketing issues them all). */
enum InvoiceType: string
{
    case JasaSurvey = 'JASA_SURVEY';
    case JasaDesain = 'JASA_DESAIN';
    case Dp = 'DP';
    case Termin = 'TERMIN';
    case Pelunasan = 'PELUNASAN';
    case Tambahan = 'TAMBAHAN';

    public function label(): string
    {
        return match ($this) {
            self::JasaSurvey => 'Jasa Survey',
            self::JasaDesain => 'Jasa Desain',
            self::Dp => 'DP',
            self::Termin => 'Termin',
            self::Pelunasan => 'Pelunasan',
            self::Tambahan => 'Pekerjaan Tambahan',
        };
    }

    /** The PEMASUKAN category its verified payment is booked under. */
    public function financeCategory(): Category
    {
        return match ($this) {
            self::JasaSurvey => Category::PendapatanSurvey,
            self::JasaDesain => Category::PendapatanDesain,
            self::Dp => Category::DownPayment,
            self::Termin, self::Pelunasan, self::Tambahan => Category::Termin,
        };
    }

    /** The quotation type an invoice of this kind is issued from (service RABs bill 100% in one invoice, D4). */
    public static function forQuotation(QuotationType $type): ?self
    {
        return match ($type) {
            QuotationType::Survey => self::JasaSurvey,
            QuotationType::Desain => self::JasaDesain,
            QuotationType::Proyek => null,
        };
    }
}
