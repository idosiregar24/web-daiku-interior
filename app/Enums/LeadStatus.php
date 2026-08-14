<?php

namespace App\Enums;

/**
 * PRD §4.1 — pipeline status. Values must match the `LeadStatus` union in
 * resources/js/types/index.d.ts exactly (see .claude/rules/backend-standards.md §4).
 */
enum LeadStatus: string
{
    case FollowUp = 'FOLLOW_UP';
    case DealDesain = 'DEAL_DESAIN';
    case Closing = 'CLOSING';
    case Lost = 'LOST';
}
