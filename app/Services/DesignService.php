<?php

namespace App\Services;

use App\Enums\DesignStatus;
use App\Enums\LeadStatus;
use App\Models\Design;
use App\Models\Lead;
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

        if (! $design || ! $design->client_acc) {
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
