<?php

namespace App\Services;

use App\Enums\QuotationStatus;
use App\Models\Design;
use App\Models\Quotation;
use App\Models\QuotationApproval;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * RAB builder (Sprint 2 Week 4) + CEO→PM dual approval (Sprint 3 Week 5,
 * PRD §4.3/§6.2/§7.1 "Quotation Approval" row — CEO and PM both `U` only,
 * sequential). State machine reads each status as "last completed gate":
 * DRAFT →(submit)→ SUBMITTED →(CEO approve)→ CEO_REVIEW →(PM approve)→
 * SENT_TO_CLIENT. `PM_REVIEW` is reserved but never persisted — PM's
 * approval both closes their own gate and marks it sent in one step,
 * same simplification already applied to `SUBMITTED` (see
 * QuotationStatus's docblock). CEO/PM reject both kick back to DRAFT.
 * Sequencing is enforced by the state machine itself (PM's gate is only
 * reachable via CEO_REVIEW, which only CEO's approval produces) — same
 * pattern as LeadService's CLOSING guard, and exactly the check
 * security-standards.md §4 calls out ("approval PM ditolak kalau
 * ceo_approved_at masih null").
 * Recording the client's own SENT_TO_CLIENT → APPROVED/REJECTED decision
 * and PDF-triggered "mark as sent" are out of scope — nothing in the
 * Week 5 CSV tasks names that actor/action, so it isn't invented here.
 */
class QuotationService
{
    /**
     * PRD §4.3 "Quotation hanya bisa dibuat jika Design sudah clientAcc =
     * true" — called from DesignService::clientAcc(), never directly from
     * a controller (there's no standalone "create quotation" entry point;
     * it's always a side effect of the Client ACC trigger).
     */
    public function createFromDesign(Design $design, User $actor): Quotation
    {
        if (! $design->client_acc) {
            throw ValidationException::withMessages([
                'design_id' => 'Quotation hanya bisa dibuat dari desain yang sudah di-ACC klien.',
            ]);
        }

        if ($design->lead->quotation()->exists()) {
            throw ValidationException::withMessages([
                'design_id' => 'Lead ini sudah punya quotation.',
            ]);
        }

        return Quotation::create([
            'lead_id' => $design->lead_id,
            'status' => QuotationStatus::Draft->value,
            'created_by' => $actor->id,
        ]);
    }

    /**
     * Replaces the full RAB item list in one go (the builder UI sends the
     * whole current list on every save, not incremental add/remove calls)
     * — only while still DRAFT, matching the RBAC matrix's Estimator-only
     * CRUD and keeping edits impossible once approval has started.
     * `total_price` is always computed server-side from qty × unit_price,
     * never trusted from the client.
     */
    public function replaceItems(Quotation $quotation, array $items): Quotation
    {
        if ($quotation->status !== QuotationStatus::Draft) {
            throw ValidationException::withMessages([
                'items' => 'Item RAB hanya bisa diubah selama quotation berstatus DRAFT.',
            ]);
        }

        return DB::transaction(function () use ($quotation, $items) {
            $quotation->items()->delete();

            $total = 0;

            foreach (array_values($items) as $index => $item) {
                $totalPrice = $item['qty'] * $item['unit_price'];
                $total += $totalPrice;

                $quotation->items()->create([
                    'description' => $item['description'],
                    'qty' => $item['qty'],
                    'unit' => $item['unit'],
                    'unit_price' => $item['unit_price'],
                    'total_price' => $totalPrice,
                    'sort_order' => $index,
                ]);
            }

            $quotation->update(['total_amount' => $total]);

            return $quotation->fresh('items');
        });
    }

    /**
     * Estimator hands the draft off for review. Only reaches SUBMITTED —
     * advancing past that (CEO_REVIEW onward) is Week 5's approval flow.
     */
    public function submit(Quotation $quotation): Quotation
    {
        if ($quotation->status !== QuotationStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => 'Quotation hanya bisa disubmit dari status DRAFT.',
            ]);
        }

        if ($quotation->items()->count() === 0) {
            throw ValidationException::withMessages([
                'items' => 'Tambahkan minimal satu item RAB sebelum submit.',
            ]);
        }

        $quotation->update(['status' => QuotationStatus::Submitted->value]);

        return $quotation->fresh();
    }

    /**
     * CEO's gate. `$decision` is 'approve'|'reject' — a single entry point
     * (rather than two methods) so the "must be SUBMITTED" guard lives in
     * one place. Reject requires a note (mirrors Lead's lost_reason rule).
     */
    public function ceoDecision(Quotation $quotation, string $decision, User $actor, ?string $note = null): Quotation
    {
        if ($quotation->status !== QuotationStatus::Submitted) {
            throw ValidationException::withMessages([
                'status' => 'Quotation ini belum berstatus SUBMITTED — belum bisa direview CEO.',
            ]);
        }

        return $this->recordDecision($quotation, $decision, 'CEO', QuotationStatus::CeoReview, $actor, $note);
    }

    /**
     * PM's gate — only reachable once CEO has approved (status
     * CEO_REVIEW), which is exactly how "CEO dulu, baru PM" is enforced.
     * Approving here also marks the quotation SENT_TO_CLIENT in the same
     * step (see class docblock for why PM_REVIEW is never persisted).
     */
    public function pmDecision(Quotation $quotation, string $decision, User $actor, ?string $note = null): Quotation
    {
        if ($quotation->status !== QuotationStatus::CeoReview) {
            throw ValidationException::withMessages([
                'status' => 'Quotation ini menunggu approval CEO terlebih dahulu.',
            ]);
        }

        return $this->recordDecision($quotation, $decision, 'PM', QuotationStatus::SentToClient, $actor, $note);
    }

    private function recordDecision(
        Quotation $quotation,
        string $decision,
        string $approverRole,
        QuotationStatus $approveStatus,
        User $actor,
        ?string $note,
    ): Quotation {
        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw ValidationException::withMessages(['decision' => 'Keputusan tidak valid.']);
        }

        if ($decision === 'reject' && ! $note) {
            throw ValidationException::withMessages(['note' => 'Catatan alasan reject wajib diisi.']);
        }

        return DB::transaction(function () use ($quotation, $decision, $approverRole, $approveStatus, $actor, $note) {
            QuotationApproval::create([
                'quotation_id' => $quotation->id,
                'approver_id' => $actor->id,
                'approver_role' => $approverRole,
                'status' => $decision === 'approve' ? 'APPROVED' : 'REJECTED',
                'note' => $note,
            ]);

            $quotation->update([
                'status' => $decision === 'approve' ? $approveStatus->value : QuotationStatus::Draft->value,
            ]);

            return $quotation->fresh();
        });
    }
}
