<?php

namespace App\Enums;

/**
 * SDM (Sprint 10, §3.3) — AUTO indicators are computed from operational
 * data by `metric_key` (needs the employee's linked account); MANUAL ones
 * are filled in by HR.
 */
enum KpiIndicatorSource: string
{
    case Auto = 'AUTO';
    case Manual = 'MANUAL';
}
