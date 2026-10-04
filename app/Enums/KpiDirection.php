<?php

namespace App\Enums;

/** SDM (Sprint 10, §3.3) — whether a higher or a lower actual value is better. */
enum KpiDirection: string
{
    case HigherBetter = 'HIGHER_BETTER';
    case LowerBetter = 'LOWER_BETTER';
}
