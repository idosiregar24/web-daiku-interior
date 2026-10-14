<?php

namespace App\Enums;

/**
 * Sprint 23 §3C (replaces Sprint 12 decisions #7 and #10 — the CEO's
 * approval of a RAB Proyek and the Estimator's "Kirim ke Marketing"):
 *
 *   DIMINTA → DRAFT → SUBMITTED (waiting for PM / Asisten PM)
 *     ├─ returned (any ✘ or a note) → DRAFT, next version (Estimator)
 *     └─ approved                   → READY_TO_SEND (Marketing — every type;
 *                                     a RAB Proyek also tells the CEO, info only)
 *   READY_TO_SEND   → SENT_TO_CLIENT (Marketing sends the link)
 *   SENT_TO_CLIENT  ├─ client "Setuju" on the link       → CLIENT_APPROVED
 *                   ├─ client "Tidak Setuju" + a note    → CLIENT_REJECTED (Marketing's turn)
 *                   └─ "Klien Menolak" recorded by Marketing (WA/phone) → DRAFT, next version
 *   CLIENT_REJECTED → DRAFT, next version ("Teruskan ke Estimator", Marketing's note)
 *   any open status → CANCELLED (Marketing, with a reason)
 *
 * The CEO only adds information-only notes (QuotationNote) — never a
 * decision. WAITING_CEO and APPROVED_INTERNAL are Sprint 12 states the
 * Sprint 23 migration moved to READY_TO_SEND; CEO_REVIEW, PM_REVIEW,
 * APPROVED and REJECTED are pre-Sprint-12. None of them is written any
 * more; they stay defined for old audit/notification data.
 */
enum QuotationStatus: string
{
    /** Sprint 12 #7 — Marketing asked for the RAB; the Estimator hasn't started it yet. */
    case Diminta = 'DIMINTA';
    case Draft = 'DRAFT';
    /** Waiting for the PM / Asisten PM item review. */
    case Submitted = 'SUBMITTED';
    /** Approved by PM / Asisten PM — with Marketing, to be sent to the client. */
    case ReadyToSend = 'READY_TO_SEND';
    case SentToClient = 'SENT_TO_CLIENT';
    /** Sprint 23 K11 — the client said "Tidak Setuju" on the link; Marketing adds what's missing and forwards it. */
    case ClientRejected = 'CLIENT_REJECTED';
    case ClientApproved = 'CLIENT_APPROVED';
    case Cancelled = 'CANCELLED';

    /** @deprecated Sprint 12 — RAB Proyek approved by the PM, waiting for the CEO (removed Sprint 23 K8). */
    case WaitingCeo = 'WAITING_CEO';
    /** @deprecated Sprint 12 — approved internally, waiting for "Kirim ke Marketing" (removed Sprint 23 K10). */
    case ApprovedInternal = 'APPROVED_INTERNAL';
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
