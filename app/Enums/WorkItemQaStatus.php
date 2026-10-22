<?php

namespace App\Enums;

/**
 * Sprint 29 Sub 12 (G1) — where a RAB item stands in its QA, derived by
 * WorkItemQaService from its progress and its newest `work_item_qa_checks`
 * row: under 100 % → BELUM; 100 % and never checked, or its repair finding
 * closed → MENUNGGU; checked PERBAIKAN with the finding still open →
 * PERBAIKAN; checked LOLOS → LOLOS. A check row itself only holds LOLOS or
 * PERBAIKAN. Values must match `WorkItemQaStatus` in resources/js/types/index.d.ts.
 */
enum WorkItemQaStatus: string
{
    case Belum = 'BELUM';
    case Menunggu = 'MENUNGGU';
    case Perbaikan = 'PERBAIKAN';
    case Lolos = 'LOLOS';

    public function label(): string
    {
        return match ($this) {
            self::Belum => 'Belum 100 %',
            self::Menunggu => 'Menunggu QA',
            self::Perbaikan => 'Perlu Perbaikan',
            self::Lolos => 'Lolos QA',
        };
    }
}
