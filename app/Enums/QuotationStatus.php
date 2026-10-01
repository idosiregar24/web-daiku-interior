<?php

namespace App\Enums;

/**
 * PRD §4.3 + §6.2 + daiku_schema.sql `quotations.status` (7 states).
 *
 * QuotationService's state machine (as of Sprint 3 Week 5) only actually
 * persists DRAFT → SUBMITTED → CEO_REVIEW → SENT_TO_CLIENT (or back to
 * DRAFT on any reject) — see QuotationService's class docblock for why
 * `PmReview` is defined but never produced (same "last completed gate"
 * simplification already applied to `Submitted`). `Approved` is the
 * client's acceptance (LeadService::confirmDeal()); the client's rejection
 * returns straight to DRAFT as a new version (QuotationService::clientReject(),
 * Sprint 9) — so `Rejected`, like `PmReview`, stays reserved and is never
 * persisted.
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
