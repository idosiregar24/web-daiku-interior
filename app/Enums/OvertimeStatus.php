<?php

namespace App\Enums;

/**
 * PRD §6.6 — Tukang ajukan → PENDING → PM approve → PENDING_FINANCE →
 * Finance approve → APPROVED_FINANCE (either gate may REJECT).
 *
 * Deviations from daiku_schema.sql's ENUM, kept deliberately:
 * - `PENDING` rather than `PENDING_PM` (shipped value, same meaning).
 * - No `APPROVED_PM`: PRD §6.6 has no action that moves a request from
 *   APPROVED_PM to PENDING_FINANCE, so the two can't both be states. The
 *   first build used APPROVED_PM for "awaiting Finance"; Sprint 8 renamed
 *   it to PENDING_FINANCE (see the 2026_09_28 overtime migration).
 */
enum OvertimeStatus: string
{
    case Pending = 'PENDING';
    case PendingFinance = 'PENDING_FINANCE';
    case ApprovedFinance = 'APPROVED_FINANCE';
    case Rejected = 'REJECTED';
}
