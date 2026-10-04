<?php

namespace App\Enums;

/** SDM (Sprint 10, §3.3) — a CLOSED month's scores are locked for good. */
enum KpiPeriodStatus: string
{
    case Open = 'OPEN';
    case Closed = 'CLOSED';
}
