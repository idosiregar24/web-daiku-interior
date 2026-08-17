<?php

namespace App\Enums;

/**
 * PRD §4.3 + §6.2 + daiku_schema.sql `quotations.status` (7 states).
 *
 * QuotationService's state machine (as of Sprint 3 Week 5) only actually
 * persists DRAFT → SUBMITTED → CEO_REVIEW → SENT_TO_CLIENT (or back to
 * DRAFT on any reject) — see QuotationService's class docblock for why
 * `PmReview` is defined but never produced (same "last completed gate"
 * simplification already applied to `Submitted`). `Approved`/`Rejected`
 * are reserved for the client's own SENT_TO_CLIENT decision, which no
 * sprint has scheduled an actor/action for yet.
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
