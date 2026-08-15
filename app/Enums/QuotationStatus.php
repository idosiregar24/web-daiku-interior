<?php

namespace App\Enums;

/**
 * PRD §4.3 + §6.2 + daiku_schema.sql `quotations.status` (7 states).
 *
 * Only DRAFT/SUBMITTED are actually produced by QuotationService this
 * sprint (.claude/plan/sprint-02.md Week 4 — "QuotationController +
 * QuotationService + Form Request", RAB builder only). CeoReview/PmReview/
 * SentToClient/Approved/Rejected are reserved for the dual-approval flow,
 * which `.claude/plan/sprint-03.md` Week 5 schedules explicitly
 * ("Quotation approval flow: CEO approve → PM approve (sequential)") —
 * defined here now for schema completeness, not wired to any transition
 * yet.
 */
enum QuotationStatus: string
{
    case Draft = 'DRAFT';
    case Submitted = 'SUBMITTED';
    case CeoReview = 'CEO_REVIEW';
    case PmReview = 'PM_REVIEW';
    case SentToClient = 'SENT_TO_CLIENT';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
}
