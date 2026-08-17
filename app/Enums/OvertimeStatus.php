<?php

namespace App\Enums;

/**
 * PRD §4.5/§6.6 — matches the already-shipped `overtime_requests.status`
 * migration comment exactly ("PENDING/APPROVED_PM/APPROVED_FINANCE/REJECTED"),
 * a simpler 4-state flow than daiku_schema.sql's 5-state ENUM
 * (PENDING_PM/APPROVED_PM/PENDING_FINANCE/APPROVED_FINANCE/REJECTED) —
 * that decision predates this sprint (see the migration itself) and is
 * kept as-is rather than reconciled now. `PENDING_FINANCE` is skipped the
 * same way `SUBMITTED`/`PM_REVIEW` are skipped in QuotationStatus:
 * APPROVED_PM already means "awaiting Finance", no separate resting state.
 */
enum OvertimeStatus: string
{
    case Pending = 'PENDING';
    case ApprovedPm = 'APPROVED_PM';
    case ApprovedFinance = 'APPROVED_FINANCE';
    case Rejected = 'REJECTED';
}
