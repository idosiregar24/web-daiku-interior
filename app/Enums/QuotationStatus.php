<?php

namespace App\Enums;

/**
 * Sprint 12 decisions #7–#10 (replaces PRD §4.3/§6.2's CEO → PM order):
 *
 *   DIMINTA → DRAFT → SUBMITTED (waiting for PM / Asisten PM)
 *     ├─ returned (any ✘)          → DRAFT, next version
 *     └─ approved ─ SURVEY/DESAIN  → APPROVED_INTERNAL
 *                 └ PROYEK         → WAITING_CEO → (returned → DRAFT) / APPROVED_INTERNAL
 *   APPROVED_INTERNAL → READY_TO_SEND (Estimator → Marketing)
 *   READY_TO_SEND     → SENT_TO_CLIENT (Marketing)
 *   SENT_TO_CLIENT    → CLIENT_APPROVED (deal) / DRAFT next version (client rejected)
 *   any open status   → CANCELLED (Marketing, with a reason)
 *
 * CEO_REVIEW, PM_REVIEW, APPROVED and REJECTED are the pre-Sprint-12
 * states. They are never persisted any more (the Sprint 12 migration
 * moved existing rows) but stay defined for old audit/notification data.
 */
enum QuotationStatus: string
{
    /** Sprint 12 #7 — Marketing asked for the RAB; the Estimator hasn't started it yet. */
    case Diminta = 'DIMINTA';
    case Draft = 'DRAFT';
    /** Waiting for the PM / Asisten PM item review. */
    case Submitted = 'SUBMITTED';
    /** RAB Proyek only — PM approved, waiting for the CEO. */
    case WaitingCeo = 'WAITING_CEO';
    case ApprovedInternal = 'APPROVED_INTERNAL';
    /** The Estimator handed the final RAB to Marketing. */
    case ReadyToSend = 'READY_TO_SEND';
    case SentToClient = 'SENT_TO_CLIENT';
    case ClientApproved = 'CLIENT_APPROVED';
    case Cancelled = 'CANCELLED';

    /** @deprecated pre-Sprint-12 — "CEO approved, waiting for PM". */
    case CeoReview = 'CEO_REVIEW';
    /** @deprecated pre-Sprint-12 — reserved, never persisted. */
    case PmReview = 'PM_REVIEW';
    /** @deprecated pre-Sprint-12 — client accepted; now CLIENT_APPROVED. */
    case Approved = 'APPROVED';
    /** @deprecated pre-Sprint-12 — reserved, never persisted. */
    case Rejected = 'REJECTED';

    /** Still running — a lead can't ask for another RAB of the same type meanwhile. */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::ClientApproved, self::Cancelled, self::Approved, self::Rejected], true);
    }

    /** @return list<string> */
    public static function closedValues(): array
    {
        return array_values(array_map(
            fn (self $status) => $status->value,
            array_filter(self::cases(), fn (self $status) => ! $status->isOpen()),
        ));
    }
}
