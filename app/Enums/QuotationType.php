<?php

namespace App\Enums;

/**
 * Sprint 12 decision #6 — Jasa Survey and Jasa Desain are optional and in
 * any order; the project RAB always comes last and doesn't require an
 * accepted design (the client may bring their own).
 */
enum QuotationType: string
{
    case Survey = 'SURVEY';
    case Desain = 'DESAIN';
    case Proyek = 'PROYEK';

    public function label(): string
    {
        return match ($this) {
            self::Survey => 'RAB Jasa Survey',
            self::Desain => 'RAB Jasa Desain',
            self::Proyek => 'RAB Proyek',
        };
    }
}
