<?php

namespace App\Services;

use App\Enums\DesignStatus;
use App\Enums\InvoiceType;
use App\Enums\LeadStatus;
use App\Enums\QuotationStatus;
use App\Enums\QuotationType;
use App\Models\Design;
use App\Models\DesignDiscussion;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class DesignService
{
    /**
     * Downstream events that move a design along on their own — reported
     * by QuotationService, LeadService::confirmDeal() and QaFormService,
     * decided in syncWithPipeline() (Sprint 9 decision #4).
     */
    public const EVENT_QUOTATION_DRAFTED = 'quotation_drafted';

    public const EVENT_QUOTATION_SENT = 'quotation_sent';

    public const EVENT_CLIENT_REJECTED = 'client_rejected';

    public const EVENT_DEAL_CONFIRMED = 'deal_confirmed';

    public const EVENT_PROJECT_COMPLETED = 'project_completed';

    /**
     * Stages that only exist once the client has approved the design:
     * the RAB, the offer and production all hang off Client ACC (PRD
     * §4.2/§4.3), so a manual update can't pick them while `client_acc`
     * is still false. Mirrored by `ACC_REQUIRED_STATUSES` in
     * Pages/Design/Show.tsx.
     */
    public const STATUSES_REQUIRING_CLIENT_ACC = [
        DesignStatus::AccDesain,
        DesignStatus::GambarRab,
        DesignStatus::PembuatanPenawaran,
        DesignStatus::WaitingAccPenawaran,
        DesignStatus::Produksi,
        DesignStatus::DoneProduksi,
        DesignStatus::RejectProduksi,
    ];

    /**
     * PRD §4.2's main pipeline, in order. The branch states
     * (REJECT_PRODUKSI, HOLD_CLIENT, REVISI_CLIENT) are deliberately
     * absent: they are a person's call, and automatic sync never
     * overrides them.
     */
    private const MAIN_PIPELINE = [
        DesignStatus::Brief,
        DesignStatus::Desain,
        DesignStatus::WaitingAccDesain,
        DesignStatus::RevisiDesain,
        DesignStatus::AccDesain,
        DesignStatus::GambarRab,
        DesignStatus::PembuatanPenawaran,
        DesignStatus::WaitingAccPenawaran,
        DesignStatus::Produksi,
        DesignStatus::DoneProduksi,
    ];

    private const PIPELINE_TARGETS = [
        self::EVENT_QUOTATION_DRAFTED => DesignStatus::PembuatanPenawaran,
        self::EVENT_QUOTATION_SENT => DesignStatus::WaitingAccPenawaran,
        self::EVENT_CLIENT_REJECTED => DesignStatus::PembuatanPenawaran,
        self::EVENT_DEAL_CONFIRMED => DesignStatus::Produksi,
        self::EVENT_PROJECT_COMPLETED => DesignStatus::DoneProduksi,
    ];

    public function __construct(
        private QuotationService $quotationService,
        private NotificationService $notificationService,
        private AuditLogService $auditLogService,
    ) {}

    /**
     * PRD §4.1 "Konversi ke Desain: Ketika status DEAL_DESAIN, sistem
     * membuka modul Desain untuk lead tersebut" — a Design record can
     * only be opened for a lead that has actually reached DEAL_DESAIN,
     * and only once (`leads.id` is unique on `designs`).
     */
    public function create(Lead $lead, array $data): Design
    {
        if ($lead->status !== LeadStatus::DealDesain) {
            throw ValidationException::withMessages([
                'lead_id' => 'Modul Desain hanya bisa dibuka untuk lead berstatus DEAL_DESAIN.',
            ]);
        }

        if ($lead->design()->exists()) {
            throw ValidationException::withMessages([
                'lead_id' => 'Lead ini sudah punya proyek desain.',
            ]);
        }

        return Design::create([
            ...$data,
            'lead_id' => $lead->id,
            'deadline' => $this->calculateDeadline($data['start_date'] ?? null, $data['target_hari'] ?? null),
        ]);
    }

    /**
     * Brief fields + status + sub-staff — no separate changeStatus() like
     * LeadService: PRD doesn't describe a validated transition graph for
     * the 13 DesignStatus values the way it does for LeadStatus/
     * PipelineLog, so the designer may move freely between stages, with
     * one guard: the post-ACC stages (STATUSES_REQUIRING_CLIENT_ACC) can't
     * be picked before Client ACC. Going back (e.g. REJECT_PRODUKSI →
     * DESAIN, PRD "kembali ke tahap desain ulang") is always allowed and
     * keeps `client_acc` — no second quotation is opened.
     *
     * `client_acc`/`acc_date` are excluded — those only ever change
     * through `clientAcc()` below, which also creates the Quotation.
     * `staff` (PRD §4.2 "PIC & Sub-Staff": `[{user_id, role_note}]`)
     * replaces the whole sub-staff list when given; the PIC is never also
     * kept as a sub-staff.
     */
    public function update(Design $design, array $data): Design
    {
        if ($design->status?->isLocked()) {
            throw ValidationException::withMessages([
                'status' => 'Desain ini masih terkunci — menunggu pembayaran diverifikasi Finance dan penugasan Kepala Desain.',
            ]);
        }

        // Sprint 12: a design born from a RAB Jasa Desain gets its team and
        // timeline from the Kepala Desain (assign()) and its status from
        // Marketing's actions — the brief form only edits the brief.
        if ($design->isFlowManaged()) {
            $data = array_intersect_key($data, array_flip(['jenis_project', 'brief_note', 'problem', 'design_urls']));
        }

        $staff = $data['staff'] ?? null;
        unset($data['client_acc'], $data['acc_date'], $data['staff']);

        if (isset($data['status'])) {
            $this->assertManualStatusAllowed($design, $data['status']);
        }

        $startDate = $data['start_date'] ?? $design->start_date?->toDateString();
        $targetHari = $data['target_hari'] ?? $design->target_hari;

        return DB::transaction(function () use ($design, $data, $staff, $startDate, $targetHari) {
            $design->update([
                ...$data,
                'deadline' => $this->calculateDeadline($startDate, $targetHari),
            ]);

            if ($staff !== null) {
                $design->staff()->sync(
                    collect($staff)
                        ->reject(fn (array $member) => (int) $member['user_id'] === (int) $design->pic_id)
                        ->mapWithKeys(fn (array $member) => [
                            (int) $member['user_id'] => ['role_note' => filled($member['role_note'] ?? null) ? $member['role_note'] : null],
                        ])
                        ->all(),
                );
            } elseif ($design->pic_id) {
                // No list given (a plain brief update) — the PIC may still
                // have changed to someone on the team; keep them off it.
                $design->staff()->detach($design->pic_id);
            }

            return $design;
        });
    }

    /**
     * Sprint 9 decision #4 — the single owner of every *automatic* design
     * status change, driven by what happens downstream of the design:
     *
     * - EVENT_QUOTATION_DRAFTED (RAB items saved / quotation submitted) → PEMBUATAN_PENAWARAN
     * - EVENT_QUOTATION_SENT (PM approval, SENT_TO_CLIENT)              → WAITING_ACC_PENAWARAN
     * - EVENT_CLIENT_REJECTED (client turned the offer down)            → back to PEMBUATAN_PENAWARAN
     * - EVENT_DEAL_CONFIRMED (Marketing confirmed the deal)             → PRODUKSI
     * - EVENT_PROJECT_COMPLETED (every milestone passed QA)             → DONE_PRODUKSI
     *
     * Forward only along MAIN_PIPELINE — a design already further along
     * (a designer may set later stages by hand) is left alone. The one
     * step back is a client rejection, and only from
     * WAITING_ACC_PENAWARAN. Designs in HOLD_CLIENT / REVISI_CLIENT /
     * REJECT_PRODUKSI are never overridden, nor is a design without
     * Client ACC (every stage it could be moved to requires it). Reaching
     * DONE_PRODUKSI also freezes the delay count (recalculateDelays()).
     * Called inside the caller's transaction; a lead without a design is
     * a no-op.
     */
    public function syncWithPipeline(int $leadId, string $event): ?Design
    {
        $target = self::PIPELINE_TARGETS[$event]
            ?? throw new InvalidArgumentException("Unknown design pipeline event [{$event}].");

        $design = Design::where('lead_id', $leadId)->first();

        // Sprint 12 designs stop at ACC_DESAIN — the RAB / offer /
        // production stages are the quotation's and the project's now.
        if (! $design || ! $design->client_acc || $design->isFlowManaged()) {
            return $design;
        }

        $currentRank = array_search($design->status, self::MAIN_PIPELINE, true);

        if ($currentRank === false) {
            return $design;
        }

        $moves = $event === self::EVENT_CLIENT_REJECTED
            ? $design->status === DesignStatus::WaitingAccPenawaran
            : array_search($target, self::MAIN_PIPELINE, true) > $currentRank;

        if ($moves) {
            $design->update(['status' => $target->value]);
        }

        return $design;
    }

    private function assertManualStatusAllowed(Design $design, DesignStatus|string $status): void
    {
        $status = $status instanceof DesignStatus ? $status : DesignStatus::from($status);

        // Only a *change* is checked, so re-saving a brief never trips over
        // a status that predates this rule.
        if ($status === $design->status || $design->client_acc) {
            return;
        }

        if (in_array($status, self::STATUSES_REQUIRING_CLIENT_ACC, true)) {
            throw ValidationException::withMessages([
                'status' => 'Status '.str_replace('_', ' ', $status->value).' baru bisa dipilih setelah desain di-ACC klien (tombol "Client ACC").',
            ]);
        }
    }

    /**
     * PRD §4.2 "Client ACC: Konfirmasi ACC desain → trigger ke tahap
     * Gambar RAB → Penawaran" + §4.3 "Quotation hanya bisa dibuat jika
     * Design sudah clientAcc = true". Only actionable once the design has
     * actually reached WAITING_ACC_DESAIN (client has something to
     * approve) — moves straight to GAMBAR_RAB since that's exactly the
     * stage the newly-created Quotation represents, no separate manual
     * step needed to leave ACC_DESAIN.
     */
    public function clientAcc(Design $design, User $actor): Design
    {
        if ($design->client_acc) {
            throw ValidationException::withMessages([
                'client_acc' => 'Desain ini sudah di-ACC klien.',
            ]);
        }

        if ($design->status !== DesignStatus::WaitingAccDesain) {
            throw ValidationException::withMessages([
                'client_acc' => 'Client ACC hanya bisa dikonfirmasi saat status WAITING_ACC_DESAIN.',
            ]);
        }

        if ($design->isFlowManaged()) {
            return $this->markClientApproved($design, $actor);
        }

        return DB::transaction(function () use ($design, $actor) {
            $design->update([
                'client_acc' => true,
                'acc_date' => now()->toDateString(),
                'status' => DesignStatus::GambarRab->value,
            ]);

            $quotation = $this->quotationService->createFromDesign($design->fresh(), $actor);

            // PRD §4.9 "Desain ACC oleh klien → Estimator, PM". No Project
            // (and so no assigned PM) exists yet at this stage, so every
            // PM is told — same division-level addressing as Finance/QA.
            $this->notificationService->notifyRoles(
                ['ESTIMATOR', 'PM'],
                'design_acc',
                'Desain di-ACC Klien',
                "Desain untuk \"{$design->lead->client_name}\" sudah di-ACC klien — draft quotation (RAB) siap disusun.",
                ['design_id' => $design->id, 'quotation_id' => $quotation->id, 'lead_id' => $design->lead_id],
            );

            return $design->fresh();
        });
    }

    // ── Sprint 12 Sub 8: RAB Jasa Desain → bayar → Kepala Desain → Marketing ──

    /**
     * Decision #16 — the client approved a RAB Jasa Desain on its link
     * (listener on QuotationClientApproved): the design is opened locked
     * (MENUNGGU_BAYAR, no PIC yet) and the Kepala Desain told "disetujui
     * client, belum bayar". A lead that already has a design (one per
     * lead) keeps it untouched — returns null then.
     */
    public function openFromQuotation(Quotation $quotation): ?Design
    {
        if ($quotation->type !== QuotationType::Desain || Design::where('lead_id', $quotation->lead_id)->exists()) {
            return null;
        }

        $design = Design::create([
            'lead_id' => $quotation->lead_id,
            'quotation_id' => $quotation->id,
            'status' => DesignStatus::MenungguBayar->value,
            'brief_note' => $quotation->request_note,
        ]);

        $this->notificationService->notifyRoles(
            ['KEPALA_DESAIN'],
            'design_awaiting_payment',
            'Desain Disetujui Klien — Belum Bayar',
            "Klien \"{$quotation->lead->client_name}\" menyetujui RAB Jasa Desain. Desain terkunci sampai pembayarannya diverifikasi Finance.",
            ['design_id' => $design->id, 'lead_id' => $design->lead_id],
        );

        return $design;
    }

    /**
     * Decision #16 — Finance verified the Jasa Desain invoice (listener on
     * InvoiceVerified): MENUNGGU_BAYAR → MENUNGGU_PENUGASAN, the Kepala
     * Desain told "siap dikerjakan". The only way out of MENUNGGU_BAYAR.
     */
    public function unlockAfterPayment(Invoice $invoice): ?Design
    {
        if ($invoice->type !== InvoiceType::JasaDesain || $invoice->quotation_id === null) {
            return null;
        }

        $design = Design::where('quotation_id', $invoice->quotation_id)->lockForUpdate()->first();

        if (! $design || $design->status !== DesignStatus::MenungguBayar) {
            return $design;
        }

        $design->update(['status' => DesignStatus::MenungguPenugasan->value]);

        $this->notificationService->notifyRoles(
            ['KEPALA_DESAIN'],
            'design_ready_to_assign',
            'Desain Siap Dikerjakan',
            "Pembayaran jasa desain \"{$design->lead->client_name}\" sudah diverifikasi — tugaskan arsiteknya.",
            ['design_id' => $design->id, 'lead_id' => $design->lead_id],
        );

        return $design;
    }

    /**
     * Decision #15 — a Kepala Desain picks the PIC architect (themself
     * allowed), the assistants and the timeline. The first assignment
     * moves MENUNGGU_PENUGASAN → DESAIN; it can be redone while the design
     * is still being worked on (before the client's approval). Never
     * before the payment is verified.
     *
     * @param  array{pic_id: int, assistant_ids?: array<int, int>, start_date: string, target_hari: int}  $data
     */
    public function assign(Design $design, array $data, User $actor): Design
    {
        return DB::transaction(function () use ($design, $data, $actor) {
            $design = Design::lockForUpdate()->findOrFail($design->id);

            if ($design->status === DesignStatus::MenungguBayar) {
                throw ValidationException::withMessages(['pic_id' => 'Desain belum bisa ditugaskan — pembayaran jasa desain belum diverifikasi Finance.']);
            }

            $assignable = [DesignStatus::MenungguPenugasan, DesignStatus::Desain, DesignStatus::RevisiDesain, DesignStatus::WaitingAccDesain];

            if (! $design->isFlowManaged() || ! in_array($design->status, $assignable, true)) {
                throw ValidationException::withMessages(['pic_id' => 'Desain ini tidak sedang menunggu penugasan.']);
            }

            $before = ['status' => $design->status->value, 'pic_id' => $design->pic_id, 'assistant_ids' => $design->staff()->pluck('users.id')->all()];
            $picId = (int) $data['pic_id'];
            $assistantIds = collect($data['assistant_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->reject(fn (int $id) => $id === $picId)
                ->unique()
                ->values();
            $targetHari = (int) $data['target_hari'];

            $design->update([
                'pic_id' => $picId,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
                'start_date' => $data['start_date'],
                'target_hari' => $targetHari,
                'deadline' => $this->calculateDeadline($data['start_date'], $targetHari),
                'status' => ($design->status === DesignStatus::MenungguPenugasan ? DesignStatus::Desain : $design->status)->value,
            ]);

            // An assistant who stays on the team keeps their role note.
            $notes = $design->staff()->pluck('design_staff.role_note', 'users.id');
            $design->staff()->sync(
                $assistantIds->mapWithKeys(fn (int $id) => [$id => ['role_note' => $notes[$id] ?? null]])->all(),
            );

            $this->auditLogService->record('design.assigned', $design, $before, [
                'status' => $design->status->value,
                'pic_id' => $picId,
                'assistant_ids' => $assistantIds->all(),
                'start_date' => $data['start_date'],
                'target_hari' => $targetHari,
            ], $actor);

            $this->notificationService->notifyMany(
                User::whereIn('id', [...$assistantIds->all(), $picId])->where('id', '!=', $actor->id)->get(),
                'design_assigned',
                'Desain Ditugaskan',
                "{$actor->name} menugaskan Anda pada desain \"{$design->lead->client_name}\" — deadline {$design->deadline->translatedFormat('d M Y')}.",
                ['design_id' => $design->id],
            );

            return $design->fresh();
        });
    }

    /** Decision #17 — Marketing sends the architect's design (≥ 1 link) to the client: → WAITING_ACC_DESAIN. */
    public function sendToClient(Design $design, User $actor): Design
    {
        return DB::transaction(function () use ($design, $actor) {
            $design = $this->lockedFlowDesign($design, [DesignStatus::Desain, DesignStatus::RevisiDesain], 'Desain hanya bisa dikirim ke klien saat sedang dikerjakan atau direvisi.');

            if (empty($design->design_urls)) {
                throw ValidationException::withMessages(['status' => 'Arsitek belum mengunggah link desain.']);
            }

            $design->update(['status' => DesignStatus::WaitingAccDesain->value, 'sent_to_client_at' => now()]);

            $this->notifyTeam($design, $actor, 'design_sent_to_client', 'Desain Dikirim ke Klien', "{$actor->name} mengirim desain \"{$design->lead->client_name}\" ke klien.");

            return $design;
        });
    }

    /**
     * Decision #17 — the client wants changes: Marketing asks the
     * architect (note required). WAITING_ACC_DESAIN → REVISI_DESAIN, the
     * revision is counted (no limit, not part of the architect's KPI).
     */
    public function requestRevision(Design $design, string $note, User $actor): Design
    {
        return DB::transaction(function () use ($design, $note, $actor) {
            $design = $this->lockedFlowDesign($design, [DesignStatus::WaitingAccDesain], 'Revisi hanya bisa diminta saat desain menunggu persetujuan klien.');
            $count = $design->revision_count + 1;

            $design->update(['status' => DesignStatus::RevisiDesain->value, 'revision_count' => $count]);
            $design->revisions()->create(['sequence' => $count, 'note' => trim($note), 'requested_by' => $actor->id]);

            $this->auditLogService->record('design.revision_requested', $design, [
                'status' => DesignStatus::WaitingAccDesain->value,
                'revision_count' => $count - 1,
            ], [
                'status' => DesignStatus::RevisiDesain->value,
                'revision_count' => $count,
                'note' => trim($note),
            ], $actor);

            $this->notifyTeam($design, $actor, 'design_revision_requested', "Revisi Desain #{$count}", "Klien \"{$design->lead->client_name}\" minta revisi: ".trim($note));

            return $design;
        });
    }

    /**
     * Decision #17 — the client approved the design: → ACC_DESAIN (the
     * design's own work is done) and the Estimator is asked for the RAB
     * Proyek built on it (decision #18) — a DIMINTA quotation, unless one
     * is already running or the lead is closed.
     */
    public function markClientApproved(Design $design, User $actor): Design
    {
        return DB::transaction(function () use ($design, $actor) {
            $design = $this->lockedFlowDesign($design, [DesignStatus::WaitingAccDesain], 'Desain hanya bisa disetujui saat menunggu persetujuan klien.');

            $design->update([
                'status' => DesignStatus::AccDesain->value,
                'client_acc' => true,
                'acc_date' => now()->toDateString(),
            ]);

            $this->auditLogService->record('design.client_approved', $design, ['status' => DesignStatus::WaitingAccDesain->value], [
                'status' => DesignStatus::AccDesain->value,
                'revision_count' => $design->revision_count,
            ], $actor);

            $lead = $design->lead;
            $projectRabRunning = $lead->quotations()
                ->where('type', QuotationType::Proyek->value)
                ->whereNotIn('status', QuotationStatus::closedValues())
                ->exists();

            if (! $projectRabRunning && ! in_array($lead->status, [LeadStatus::Lost, LeadStatus::Closing], true)) {
                $this->quotationService->request($lead, QuotationType::Proyek, "Desain disetujui klien — susun RAB Proyek dari desain ini (revisi {$design->revision_count}x).", $actor);
            } else {
                $this->notificationService->notifyRoles(
                    ['ESTIMATOR'],
                    'design_acc',
                    'Desain Disetujui Klien',
                    "Desain \"{$lead->client_name}\" disetujui klien — dasar RAB Proyek.",
                    ['design_id' => $design->id, 'lead_id' => $lead->id],
                );
            }

            $this->notifyTeam($design, $actor, 'design_client_approved', 'Desain Disetujui Klien', "Desain \"{$lead->client_name}\" disetujui klien.");

            return $design;
        });
    }

    /**
     * D6 — a message in the design's Arsitek ↔ Estimator thread, optionally
     * about one RAB of the same lead. Everyone else in the conversation is
     * told: the design's architects, whoever already wrote, and the
     * Estimator(s) building this lead's RABs (every Estimator if none yet).
     *
     * @param  array{body: string, attachment_url?: ?string, quotation_id?: ?int}  $data
     */
    public function discuss(Design $design, array $data, User $actor): DesignDiscussion
    {
        $quotationId = $data['quotation_id'] ?? null;

        if ($quotationId !== null && ! Quotation::whereKey($quotationId)->where('lead_id', $design->lead_id)->exists()) {
            throw ValidationException::withMessages(['quotation_id' => 'RAB ini bukan milik lead desain tersebut.']);
        }

        return DB::transaction(function () use ($design, $data, $quotationId, $actor) {
            $previousWriters = User::whereIn('id', $design->discussions()->select('user_id'))->get();

            $message = $design->discussions()->create([
                'quotation_id' => $quotationId,
                'user_id' => $actor->id,
                'body' => trim($data['body']),
                'attachment_url' => filled($data['attachment_url'] ?? null) ? $data['attachment_url'] : null,
            ]);

            $estimatorIds = Quotation::where('lead_id', $design->lead_id)
                ->whereIn('created_by', User::role('ESTIMATOR')->select('id'))
                ->pluck('created_by');
            $estimators = $estimatorIds->isNotEmpty()
                ? User::whereIn('id', $estimatorIds)->get()
                : User::role('ESTIMATOR')->get();

            $this->notificationService->notifyMany(
                collect([$design->pic])
                    ->merge($design->staff)
                    ->merge($previousWriters)
                    ->merge($estimators)
                    ->filter(fn (?User $user) => $user && $user->id !== $actor->id && $user->is_active),
                'design_discussion',
                "Diskusi Desain — {$design->lead->client_name}",
                "{$actor->name}: ".str($message->body)->limit(120),
                ['design_id' => $design->id, 'quotation_id' => $quotationId],
            );

            return $message;
        });
    }

    /**
     * The design's thread as the Design and Quotation pages show it (D6),
     * or null for a viewer who may not see the design.
     *
     * @return array{designId: int, messages: list<array<string, mixed>>, canPost: bool}|null
     */
    public function threadFor(Design $design, User $user): ?array
    {
        if (! $user->can('view', $design)) {
            return null;
        }

        return [
            'designId' => $design->id,
            'messages' => $design->discussions()
                ->with(['user:id,name', 'quotation:id,type,version'])
                ->oldest('id')
                ->get()
                ->map(fn (DesignDiscussion $message) => [
                    'id' => $message->id,
                    'body' => $message->body,
                    'attachment_url' => $message->attachment_url,
                    'user_name' => $message->user?->name,
                    'quotation' => $message->quotation ? ['id' => $message->quotation->id, 'type' => $message->quotation->type, 'version' => $message->quotation->version] : null,
                    'created_at' => $message->created_at,
                ])
                ->all(),
            'canPost' => $user->can('discuss', $design),
        ];
    }

    /**
     * Row-locked re-read of a Sprint 12 design in one of `$allowed`.
     *
     * @param  list<DesignStatus>  $allowed
     */
    private function lockedFlowDesign(Design $design, array $allowed, string $message): Design
    {
        $design = Design::lockForUpdate()->findOrFail($design->id);

        if (! $design->isFlowManaged() || ! in_array($design->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }

        return $design;
    }

    /** The design's architects (PIC + assistants), minus whoever acted. */
    private function notifyTeam(Design $design, User $actor, string $type, string $title, string $message): void
    {
        $this->notificationService->notifyMany(
            collect([$design->pic])->merge($design->staff)->filter(fn (?User $user) => $user && $user->id !== $actor->id),
            $type,
            $title,
            $message,
            ['design_id' => $design->id],
        );
    }

    /**
     * PRD §4.2 "Sistem hitung delay_hari otomatis setiap hari" — run daily
     * by DesignDelayJob. Delay accumulates one counted day at a time from
     * `max(deadline, delay_counted_on)` to today, so:
     * - re-running on the same day adds nothing (idempotent);
     * - days spent in HOLD_CLIENT/REVISI_CLIENT are marked counted without
     *   adding delay ("tidak menghitung delay — waktu ditangguhkan");
     * - DONE_PRODUKSI freezes the final delay;
     * - a deadline moved into the future (target_hari extended) resets it.
     *
     * Returns the number of designs whose delay changed.
     */
    public function recalculateDelays(?Carbon $today = null): int
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $suspended = [DesignStatus::HoldClient, DesignStatus::RevisiClient];
        $changed = 0;

        Design::query()
            ->whereNotNull('deadline')
            ->where('status', '!=', DesignStatus::DoneProduksi->value)
            // Decision #16: nothing runs before it's paid and assigned; a
            // Sprint 12 design's own work ends at the client's approval.
            ->whereNotIn('status', DesignStatus::lockedValues())
            ->where(fn ($query) => $query->whereNull('quotation_id')->orWhere('client_acc', false))
            ->eachById(function (Design $design) use ($today, $suspended, &$changed) {
                if ($design->deadline->gte($today)) {
                    if ($design->delay_hari !== 0 || $design->delay_counted_on !== null) {
                        $design->update(['delay_hari' => 0, 'delay_counted_on' => null]);
                        $changed++;
                    }

                    return;
                }

                $from = $design->delay_counted_on?->gt($design->deadline)
                    ? $design->delay_counted_on
                    : $design->deadline;
                $newDays = (int) $from->diffInDays($today);

                if ($newDays <= 0) {
                    return;
                }

                $addDelay = in_array($design->status, $suspended, true) ? 0 : $newDays;

                $design->update([
                    'delay_hari' => $design->delay_hari + $addDelay,
                    'delay_counted_on' => $today->toDateString(),
                ]);

                if ($addDelay > 0) {
                    $changed++;
                }
            });

        return $changed;
    }

    private function calculateDeadline(?string $startDate, ?int $targetHari): ?string
    {
        if (! $startDate || ! $targetHari) {
            return null;
        }

        return Carbon::parse($startDate)->addDays($targetHari)->toDateString();
    }
}
