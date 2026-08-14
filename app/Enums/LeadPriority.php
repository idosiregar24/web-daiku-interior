<?php

namespace App\Enums;

/**
 * PRD §4.1 — "sesuai operasional nyata tim marketing Daiku". Note the PRD
 * §5.1 schema sketch says HIGH/MEDIUM/LOW, but §4.1's feature description
 * is more specific and explicit about HOT/WARM/COLD — this follows §4.1,
 * matching the `LeadPriority` union already committed to in
 * resources/js/types/index.d.ts.
 */
enum LeadPriority: string
{
    case Hot = 'HOT';
    case Warm = 'WARM';
    case Cold = 'COLD';
}
