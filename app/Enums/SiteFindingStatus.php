<?php

namespace App\Enums;

/** Sprint 24 D12 — a finishing finding on site: open (holds QA & completion) until fixed. */
enum SiteFindingStatus: string
{
    case Terbuka = 'TERBUKA';
    case Selesai = 'SELESAI';
}
