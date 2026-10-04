<?php

namespace App\Services;

use App\Enums\QuotationStatus;
use App\Models\Design;
use App\Models\Quotation;
use App\Models\QuotationApproval;
use App\Models\QuotationItem;
use App\Models\QuotationRevision;
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
 *
 * After SENT_TO_CLIENT (Sprint 9 decision #3, PRD §6.2): the client's
 * acceptance is recorded by LeadService::confirmDeal() (→ APPROVED), their
 * rejection by clientReject() — straight back to DRAFT for a revision, so
 * `REJECTED` stays unpersisted like the other "in between" states. Every
 * rejection that returns the quotation to DRAFT (CEO, PM or client) closes
 * the rejected version into `quotation_revisions` and opens the next
 * version number (closeVersion()); PM's approval starts the
 * VALIDITY_DAYS offer period (`valid_until`). The lead's design follows
 * each step via DesignService::syncWithPipeline().
 */
class QuotationService
{
    /** PRD §4.3 "Validity Period: Tanggal berlaku penawaran (default 14 hari dari tanggal kirim)". */
    public const VALIDITY_DAYS = 14;

    /**
     * Decision gates, keyed by QuotationApproval::approver_role: the
     * status the quotation must be in, the status an approval moves it to
     * (null for CLIENT — only the rejection is recorded here, acceptance
     * is LeadService::confirmDeal()), and the error when it isn't there.
     */
    private const GATES = [
        'CEO' => [QuotationStatus::Submitted, QuotationStatus::CeoReview, 'Quotation ini belum berstatus SUBMITTED — belum bisa direview CEO.'],
        'PM' => [QuotationStatus::CeoReview, QuotationStatus::SentToClient, 'Quotation ini menunggu approval CEO terlebih dahulu.'],
        'CLIENT' => [QuotationStatus::SentToClient, null, 'Penolakan klien hanya bisa dicatat saat penawaran berstatus SENT_TO_CLIENT (sudah disetujui CEO & PM).'],
    ];

    public function __construct(
        private NotificationService $notificationService,
        private AuditLogService $auditLogService,
    ) {}

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
     * never trusted from the client. A rejected version's items survive
     * in its QuotationRevision snapshot, so overwriting them here is safe.
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
                // qty may be fractional (2,5 m²) — round the line to the cent.
                $totalPrice = round((float) $item['qty'] * (float) $item['unit_price'], 2);
                $total += $totalPrice;

                $quotation->items()->create([
                    'description' => $item['description'],
                    'qty' => $item['qty'],
                    'unit_id' => $item['unit_id'],
                    'unit_price' => $item['unit_price'],
                    'total_price' => $totalPrice,
                    'sort_order' => $index,
                ]);
            }

            $quotation->update(['total_amount' => $total]);

            // The RAB is being written — the design is now "Pembuatan Penawaran".
            $this->designService()->syncWithPipeline($quotation->lead_id, DesignService::EVENT_QUOTATION_DRAFTED);

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

        return DB::transaction(function () use ($quotation) {
            $quotation->update(['status' => QuotationStatus::Submitted->value]);

            $this->designService()->syncWithPipeline($quotation->lead_id, DesignService::EVENT_QUOTATION_DRAFTED);

            // PRD §4.9 "Quotation disubmit → CEO, PM" — CEO acts first (see
            // ceoDecision()), PM is told now so the second gate isn't a surprise.
            $this->notificationService->notifyRoles(
                ['CEO', 'PM'],
                'quotation_submitted',
                'Quotation Menunggu Approval',
                "Quotation \"{$quotation->lead->client_name}\" versi {$quotation->version} (".$this->rupiah($quotation->total_amount).') disubmit dan menunggu approval CEO.',
                ['quotation_id' => $quotation->id],
            );

            return $quotation->fresh();
        });
    }

    /**
     * CEO's gate. `$decision` is 'approve'|'reject' — a single entry point
     * (rather than two methods) so the "must be SUBMITTED" guard lives in
     * one place. Reject requires a note (mirrors Lead's lost_reason rule).
     */
    public function ceoDecision(Quotation $quotation, string $decision, User $actor, ?string $note = null): Quotation
    {
        return $this->recordDecision($quotation, 'CEO', $decision, $actor, $note);
    }

    /**
     * PM's gate — only reachable once CEO has approved (status
     * CEO_REVIEW), which is exactly how "CEO dulu, baru PM" is enforced.
     * Approving here also marks the quotation SENT_TO_CLIENT in the same
     * step (see class docblock for why PM_REVIEW is never persisted) and
     * starts its VALIDITY_DAYS validity period.
     */
    public function pmDecision(Quotation $quotation, string $decision, User $actor, ?string $note = null): Quotation
    {
        return $this->recordDecision($quotation, 'PM', $decision, $actor, $note);
    }

    /**
     * PRD §6.2 "SENT TO CLIENT → REJECTED (klien) → DRAFT (revisi)": the
     * client turned the offer down but wants a revised one (a client who
     * walks away is a LOST lead instead). Recorded by CEO/Marketing — the
     * same people who confirm the deal — as a CLIENT approval row, then
     * the version is closed and the Estimator revises a new one.
     */
    public function clientReject(Quotation $quotation, User $actor, ?string $note): Quotation
    {
        return $this->recordDecision($quotation, 'CLIENT', 'reject', $actor, $note);
    }

    private function recordDecision(Quotation $quotation, string $gate, string $decision, User $actor, ?string $note): Quotation
    {
        [$requiredStatus, $approveStatus, $notReadyMessage] = self::GATES[$gate];

        $this->assertStatus($quotation, $requiredStatus, $notReadyMessage);

        if (! in_array($decision, $approveStatus ? ['approve', 'reject'] : ['reject'], true)) {
            throw ValidationException::withMessages(['decision' => 'Keputusan tidak valid.']);
        }

        if ($decision === 'reject' && blank($note)) {
            throw ValidationException::withMessages([
                'note' => $gate === 'CLIENT' ? 'Alasan penolakan klien wajib diisi.' : 'Catatan alasan reject wajib diisi.',
            ]);
        }

        return DB::transaction(function () use ($quotation, $gate, $decision, $approveStatus, $requiredStatus, $notReadyMessage, $actor, $note) {
            // Serialize decisions on one quotation — a double-submitted
            // click must not record two decisions or close one version
            // twice: take the row lock, reload, and re-check the gate.
            Quotation::whereKey($quotation->getKey())->lockForUpdate()->first();
            $quotation->refresh();
            $this->assertStatus($quotation, $requiredStatus, $notReadyMessage);

            $oldStatus = $quotation->status;
            $oldVersion = $quotation->version;
            $oldValidUntil = $quotation->valid_until;

            QuotationApproval::create([
                'quotation_id' => $quotation->id,
                'version' => $quotation->version,
                'approver_id' => $actor->id,
                'approver_role' => $gate,
                'status' => $decision === 'approve' ? 'APPROVED' : 'REJECTED',
                'note' => $note,
            ]);

            if ($decision === 'reject') {
                $this->closeVersion($quotation, $gate, $actor, $note);
            } elseif ($approveStatus === QuotationStatus::SentToClient) {
                // PM's approval is the moment the offer goes out (PRD §4.3
                // "default 14 hari dari tanggal kirim").
                $quotation->update([
                    'status' => $approveStatus->value,
                    'valid_until' => now('Asia/Jakarta')->startOfDay()->addDays(self::VALIDITY_DAYS)->toDateString(),
                ]);
            } else {
                $quotation->update(['status' => $approveStatus->value]);
            }

            if ($decision === 'approve' && $approveStatus === QuotationStatus::SentToClient) {
                $this->designService()->syncWithPipeline($quotation->lead_id, DesignService::EVENT_QUOTATION_SENT);
            } elseif ($gate === 'CLIENT') {
                $this->designService()->syncWithPipeline($quotation->lead_id, DesignService::EVENT_CLIENT_REJECTED);
            }

            // PRD §9.4 "approval quotation" — audit trail.
            $old = ['status' => $oldStatus];
            $new = ['status' => $quotation->status, 'total_amount' => $quotation->total_amount, 'note' => $note];

            if ($decision === 'reject') {
                $old['version'] = $oldVersion;
                $new['version'] = $quotation->version;
            }

            if ($quotation->wasChanged('valid_until')) {
                $old['valid_until'] = $oldValidUntil;
                $new['valid_until'] = $quotation->valid_until;
            }

            $this->auditLogService->record(
                'quotation.'.strtolower($gate).'_'.($decision === 'approve' ? 'approved' : 'rejected'),
                $quotation,
                $old,
                $new,
                $actor,
            );

            $this->notifyDecision($quotation, $decision, $gate, $note, $actor, $oldVersion);

            return $quotation->fresh();
        });
    }

    /**
     * PRD §4.3 "Versi Revisi: Sistem menyimpan riwayat revisi quotation".
     * Freezes the rejected version — its items and total, why and by whom
     * it was turned down — into an append-only QuotationRevision, then
     * reopens the quotation as the next version's DRAFT for the Estimator.
     * The validity period goes with the old version: a new one only
     * starts when the revised offer is sent again.
     */
    private function closeVersion(Quotation $quotation, string $gate, User $actor, string $note): void
    {
        QuotationRevision::create([
            'quotation_id' => $quotation->id,
            'version' => $quotation->version,
            'total_amount' => $quotation->total_amount,
            'items' => $quotation->items()->get()->map(fn (QuotationItem $item) => [
                'description' => $item->description,
                'qty' => (float) $item->qty,
                // Snapshots taken before Master Satuan (Sprint 11) hold a free-text `unit` instead.
                'unit_code' => $item->unit?->code,
                'unit_price' => $item->unit_price,
                'total_price' => $item->total_price,
            ])->all(),
            'reason' => QuotationRevision::reasonFor($gate),
            'note' => $note,
            'closed_by' => $actor->id,
        ]);

        $quotation->update([
            'status' => QuotationStatus::Draft->value,
            'version' => $quotation->version + 1,
            'valid_until' => null,
        ]);
    }

    private function assertStatus(Quotation $quotation, QuotationStatus $required, string $message): void
    {
        if ($quotation->status !== $required) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    /**
     * PRD §4.9 "Quotation approve/reject → Estimator, Marketing": the
     * Estimator who built the RAB and the Marketing owner of the lead —
     * minus whoever made the decision (a Marketing user recording their
     * own client's rejection doesn't need telling). A CEO approval
     * additionally hands the next gate to PM.
     */
    private function notifyDecision(Quotation $quotation, string $decision, string $gate, ?string $note, User $actor, int $decidedVersion): void
    {
        $quotation->loadMissing(['creator', 'lead.assignee']);
        $client = $quotation->lead->client_name;
        $metadata = ['quotation_id' => $quotation->id];

        if ($decision === 'reject' && $gate === 'CLIENT') {
            $title = 'Penawaran Ditolak Klien';
            $message = "Klien menolak penawaran \"{$client}\" versi {$decidedVersion} — quotation kembali ke DRAFT sebagai versi {$quotation->version} untuk direvisi: {$note}";
        } elseif ($decision === 'reject') {
            $title = 'Quotation Ditolak';
            $message = "Quotation \"{$client}\" versi {$decidedVersion} ditolak {$gate} dan kembali ke DRAFT sebagai versi {$quotation->version}: {$note}";
        } elseif ($gate === 'CEO') {
            $title = 'Quotation Disetujui';
            $message = "Quotation \"{$client}\" disetujui CEO, menunggu approval PM.";
        } else {
            $title = 'Quotation Disetujui';
            $message = "Quotation \"{$client}\" disetujui CEO & PM dan siap dikirim ke klien — berlaku sampai "
                .$quotation->valid_until->translatedFormat('d F Y').'.';
        }

        $this->notificationService->notifyMany(
            collect([$quotation->creator, $quotation->lead->assignee])
                ->filter()
                ->reject(fn (User $user) => $user->is($actor)),
            $decision === 'reject' ? 'quotation_rejected' : 'quotation_approved',
            $title,
            $message,
            $metadata,
        );

        if ($decision === 'approve' && $gate === 'CEO') {
            $this->notificationService->notifyRoles(
                ['PM'],
                'quotation_awaiting_pm',
                'Quotation Menunggu Approval PM',
                "Quotation \"{$client}\" sudah disetujui CEO dan menunggu approval Anda.",
                $metadata,
            );
        }
    }

    /**
     * Resolved on demand rather than constructor-injected: DesignService
     * already depends on this class (clientAcc() opens the quotation), so
     * injecting both ways would be a circular dependency.
     */
    private function designService(): DesignService
    {
        return app(DesignService::class);
    }

    private function rupiah(string|float|null $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }
}
