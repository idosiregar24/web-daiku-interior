<?php

namespace App\Services;

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Enums\NotificationType;
use App\Enums\ProjectPhase;
use App\Enums\ProjectStatus;
use App\Enums\SupervisorPaymentKind;
use App\Enums\SupervisorPaymentStatus;
use App\Enums\WeeklyReportStatus;
use App\Models\Project;
use App\Models\SupervisorFee;
use App\Models\SupervisorPayment;
use App\Models\User;
use App\Models\WeeklyReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 24 D4/D5/E3 — Jasa Pengawas. Every payment goes Pengawas (asks)
 * or PM (weekly, with the report) → PM approves → Finance pays, and the
 * total of everything asked/approved/paid never exceeds the fee the PM
 * set at the TM. Approving the DP is "Start Produksi" (PERSIAPAN →
 * PRODUKSI, Logistics told to order the materials). Paying writes a
 * PENGELUARAN "Jasa Pengawas" transaction (audited). Nothing is deleted.
 */
class SupervisorPaymentService
{
    public function __construct(
        private NotificationService $notificationService,
        private AuditLogService $auditLogService,
        private FinanceTransactionService $financeTransactionService,
        private ProjectPhaseService $phaseService,
        private TechnicalMeetingService $technicalMeetingService,
        private MilestoneService $milestoneService,
        private WorkItemService $workItemService,
    ) {}

    /** Sprint 27 P1a — at most this many items are named per condition (the rest is "dan n lainnya"). */
    private const MISSING_SHOWN = 5;

    /**
     * D5 — the Pengawas's conditions for the DP: materials ✓, wages ✓
     * (every daily target has its rate), daily targets ✓ (every timeline
     * has at least one).
     *
     * Sprint 27 P1a — each open condition also says what is missing
     * (`detail`, `missing`, `more`), so the Pengawas knows where to fix it.
     * Task titles only with `$withTaskTitles` — a viewer who sees the
     * project's tasks (ProjectPolicy::taskVisibility = all, PRD §4.6);
     * others get the count only. Timeline names are not task detail.
     *
     * @return list<array{key: string, label: string, done: bool, detail: string|null, missing: list<string>, more: int}>
     */
    public function dpRequirements(Project $project, bool $withTaskTitles = false): array
    {
        $milestones = $project->milestones()->withCount('tasks')->reorder()->orderBy('order')->get(['id', 'name', 'order']);
        $taskCount = $project->tasks()->count();
        $unpaid = $project->tasks()
            ->where(fn ($q) => $q->whereNull('rate_per_task')->orWhere('rate_per_task', '<=', 0))
            ->orderByRaw('COALESCE(planned_date, due_date)')
            ->orderBy('id')
            ->get(['id', 'title', 'planned_date', 'due_date']);
        $empty = $milestones->filter(fn ($milestone) => $milestone->tasks_count === 0)->values();
        $hasMaterials = $this->technicalMeetingService->plannedMaterials($project)->exists();
        $fee = $project->supervisorFee()->first();
        $hasFee = $fee !== null && (float) $fee->total_amount > 0;
        $perItem = $this->workItemService->hasWorkItems($project);
        $wageTotal = $perItem ? $this->workItemService->totalWage($project) : 0;

        $wagesMissing = $withTaskTitles
            ? $unpaid->take(self::MISSING_SHOWN)->map(fn ($task) => trim($task->title.' · '.(($task->planned_date ?? $task->due_date)?->format('d/m') ?? '')))->all()
            : [];

        return [
            // Sprint 29 Sub 08 (D3) — a project with work items: "upah ✓" = ≥ 1 RAB item with a Satuan Upah.
            $perItem ? [
                'key' => 'wages',
                'label' => 'Upah Tukang (minimal satu item RAB berupah)',
                'done' => $wageTotal > 0,
                'detail' => $wageTotal > 0 ? null : 'Belum ada item RAB yang diberi satuan upah.',
                'missing' => [],
                'more' => 0,
            ] : [
                'key' => 'wages',
                'label' => 'Upah (semua daily target punya upah)',
                'done' => $taskCount > 0 && $unpaid->isEmpty(),
                'detail' => $taskCount === 0
                    ? 'Belum ada daily target.'
                    : ($unpaid->isEmpty() ? null : "{$unpaid->count()} daily target belum punya upah."),
                'missing' => array_values($wagesMissing),
                'more' => $withTaskTitles ? max(0, $unpaid->count() - self::MISSING_SHOWN) : 0,
            ],
            [
                'key' => 'targets',
                'label' => 'Daily target (tiap timeline minimal satu)',
                'done' => $milestones->isNotEmpty() && $empty->isEmpty(),
                'detail' => $milestones->isEmpty()
                    ? 'Belum ada timeline.'
                    : ($empty->isEmpty() ? null : "{$empty->count()} timeline belum punya daily target."),
                'missing' => $empty->take(self::MISSING_SHOWN)->pluck('name')->values()->all(),
                'more' => max(0, $empty->count() - self::MISSING_SHOWN),
            ],
            [
                'key' => 'materials',
                'label' => 'Bahan',
                'done' => $hasMaterials,
                'detail' => $hasMaterials ? null : 'Belum ada rencana bahan.',
                'missing' => [],
                'more' => 0,
            ],
            // Sprint 29 Sub 03 — the old Jasa Pengawas value (projects without work
            // items); with work items the total is Σ upah per item (the row above).
            ...($perItem ? [] : [[
                'key' => 'fee',
                'label' => 'Nilai upah tukang & DP % diisi PM',
                'done' => $hasFee,
                'detail' => $hasFee ? null : 'PM belum mengisi nilai upah tukang (kartu Technical Meeting).',
                'missing' => [],
                'more' => 0,
            ]]),
        ];
    }

    /** "Ajukan DP Pengawas" — the project's Pengawas, at PERSIAPAN, once the conditions are met. */
    public function requestDp(Project $project, User $actor): SupervisorPayment
    {
        return DB::transaction(function () use ($project, $actor) {
            $project = Project::lockForUpdate()->findOrFail($project->id);
            $this->phaseService->ensurePhase($project, ProjectPhase::Persiapan, 'DP upah tukang diajukan saat proyek di tahap Persiapan (setelah TM selesai).');
            $fee = $this->feeOf($project);

            $missing = collect($this->dpRequirements($project))->reject(fn (array $row) => $row['done'])->pluck('label');

            if ($missing->isNotEmpty()) {
                throw ValidationException::withMessages(['dp' => 'Syarat DP belum lengkap: '.$missing->implode(', ').'.']);
            }

            if ($fee->payments()->where('kind', SupervisorPaymentKind::Dp->value)->whereIn('status', SupervisorPaymentStatus::committedValues())->exists()) {
                throw ValidationException::withMessages(['dp' => 'DP upah tukang proyek ini sudah diajukan.']);
            }

            $amount = $fee->dpAmount();

            // Sprint 29 Sub 09 (B6) — the DP splits per Kepala Tim pro rata to each team's wage.
            return $this->createRequest($project, $fee, SupervisorPaymentKind::Dp, $amount, $actor, breakdown: $this->shares($project, $amount, 'total', 0.0));
        });
    }

    /**
     * E3 — "Ajukan Pelunasan": the rest of the fee. Sprint 29 Sub 09 (B5) — a
     * project with wages per RAB item: once every item with a wage is at
     * 100 %; an older project: once it is COMPLETED.
     */
    public function requestSettlement(Project $project, User $actor): SupervisorPayment
    {
        return DB::transaction(function () use ($project, $actor) {
            $project = Project::lockForUpdate()->findOrFail($project->id);
            $perItem = $this->workItemService->hasWorkItems($project);

            if ($perItem && ! $this->workItemService->allDone($project)) {
                throw ValidationException::withMessages(['settlement' => 'Pelunasan upah tukang diajukan setelah semua item berupah mencapai 100 %.']);
            }

            if (! $perItem && $project->status !== ProjectStatus::Completed) {
                throw ValidationException::withMessages(['settlement' => 'Pelunasan upah tukang diajukan setelah proyek selesai.']);
            }

            $fee = $this->feeOf($project);

            if ($fee->payments()->whereIn('status', [SupervisorPaymentStatus::Diajukan->value])->exists()) {
                throw ValidationException::withMessages(['settlement' => 'Masih ada pengajuan pembayaran pengawas yang menunggu PM.']);
            }

            $remaining = $fee->remainingAmount();

            if ($remaining <= 0) {
                throw ValidationException::withMessages(['settlement' => 'Jasa pengawas proyek ini sudah dibayar penuh.']);
            }

            return $this->createRequest($project, $fee, SupervisorPaymentKind::Pelunasan, $remaining, $actor, breakdown: $this->shares($project, $remaining, 'progress', $fee->committedAmount()));
        });
    }

    /**
     * Sprint 29 Sub 09 (B5/C1) — "Kirim pengecekan": the pengecekan asks
     * "cair kali ini" (upah boleh cair − sudah cair) as this week's payment —
     * the PELUNASAN once every item with a wage is at 100 %. Nothing when
     * there is nothing to draw. Runs inside WeeklyReportService::submit()'s
     * transaction, the project row locked (like requestDp()).
     */
    public function requestForReport(Project $project, WeeklyReport $report, User $actor): ?SupervisorPayment
    {
        $fee = SupervisorFee::where('project_id', $project->id)->lockForUpdate()->first();

        if (! $fee) {
            return null;
        }

        $alreadyPaid = $fee->committedAmount();
        $amount = $this->workItemService->payableNow($project);

        if ($amount <= 0) {
            return null;
        }

        $kind = $this->workItemService->allDone($project) ? SupervisorPaymentKind::Pelunasan : SupervisorPaymentKind::Mingguan;

        return $this->createRequest($project, $fee, $kind, $amount, $actor, $report, $this->shares($project, $amount, 'progress', $alreadyPaid), notifyPm: false);
    }

    /**
     * The PM of the project decides a DIAJUKAN request. Approve: the amount
     * may be changed (never above what is left of the fee) → Finance's
     * queue; a DP approval starts production. Reject: a note is required;
     * the Pengawas may ask again.
     */
    public function decide(SupervisorPayment $payment, bool $approve, ?float $amount, ?string $note, User $actor, bool $fromReport = false): SupervisorPayment
    {
        return DB::transaction(function () use ($payment, $approve, $amount, $note, $actor, $fromReport) {
            $payment = SupervisorPayment::lockForUpdate()->findOrFail($payment->id);
            $project = Project::lockForUpdate()->findOrFail($payment->project_id);

            if ($payment->status !== SupervisorPaymentStatus::Diajukan) {
                throw ValidationException::withMessages(['payment' => 'Pengajuan ini sudah diputuskan.']);
            }

            // Sprint 29 Sub 09 — a pengecekan's payment is decided with its report (one decision).
            $report = $payment->weekly_report_id ? WeeklyReport::find($payment->weekly_report_id) : null;

            if (! $fromReport && $report && $report->status === WeeklyReportStatus::Diajukan) {
                throw ValidationException::withMessages(['payment' => "Putuskan lewat pengecekan {$report->label()} (tab Tahapan)."]);
            }

            if (! $approve) {
                if (blank($note)) {
                    throw ValidationException::withMessages(['note' => 'Alasan penolakan wajib diisi.']);
                }

                $payment->update([
                    'status' => SupervisorPaymentStatus::Ditolak->value,
                    'decided_by' => $actor->id,
                    'decided_at' => now(),
                    'decision_note' => trim($note),
                ]);
                $this->auditLogService->record('supervisor_payment.rejected', $payment, ['status' => SupervisorPaymentStatus::Diajukan->value], [
                    'status' => SupervisorPaymentStatus::Ditolak->value,
                    'note' => trim($note),
                ], $actor);
                if (! $fromReport) {
                    $this->notifySupervisor($payment, 'Pengajuan Ditolak PM', "Pengajuan {$payment->label} ditolak {$actor->name}: ".trim($note));
                }

                return $payment;
            }

            $amount = round($amount ?? (float) $payment->amount, 2);
            $this->ensureWithinFee($payment->fee, $amount, $payment->id);

            $payment->update([
                'amount' => $amount,
                // The PM changed the amount: the split per Kepala Tim follows.
                'breakdown' => (float) $payment->amount === $amount ? $payment->breakdown : $this->workItemService->scaleShares($payment->breakdown, $amount),
                'status' => SupervisorPaymentStatus::DisetujuiPm->value,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => filled($note) ? trim($note) : null,
            ]);
            $this->auditLogService->record('supervisor_payment.approved', $payment, ['status' => SupervisorPaymentStatus::Diajukan->value], [
                'status' => SupervisorPaymentStatus::DisetujuiPm->value,
                'amount' => $amount,
            ], $actor);

            if ($payment->kind === SupervisorPaymentKind::Dp && $project->currentPhase() === ProjectPhase::Persiapan) {
                $this->startProduction($project, $actor);
            }

            if (! $fromReport) {
                $this->notifySupervisor($payment, 'Pengajuan Disetujui PM', "Pengajuan {$payment->label} (Rp ".$this->rupiah($amount).") disetujui {$actor->name} — menunggu dibayar Finance.");
            }

            $this->notifyFinance($payment);

            return $payment;
        });
    }

    /** D10 — a weekly payment the PM approves together with the Friday report (straight to Finance). */
    public function createWeekly(WeeklyReport $report, float $amount, User $actor): SupervisorPayment
    {
        // Same lock as requestDp()/decide(): two weekly approvals at once must not
        // both pass ensureWithinFee() and together exceed the fee. Runs inside the
        // caller's transaction (WeeklyReportService::decide()).
        $project = Project::lockForUpdate()->findOrFail($report->project_id);
        $fee = $this->feeOf($project);
        $amount = round($amount, 2);
        $this->ensureWithinFee($fee, $amount);

        $payment = $fee->payments()->create([
            'project_id' => $project->id,
            'supervisor_id' => $report->supervisor_id ?? $project->supervisors()->value('users.id'),
            'kind' => SupervisorPaymentKind::Mingguan->value,
            'amount' => $amount,
            'status' => SupervisorPaymentStatus::DisetujuiPm->value,
            'weekly_report_id' => $report->id,
            'requested_by' => $report->supervisor_id,
            'requested_at' => $report->submitted_at ?? now(),
            'decided_by' => $actor->id,
            'decided_at' => now(),
        ]);
        $this->auditLogService->record('supervisor_payment.approved', $payment, null, [
            'kind' => SupervisorPaymentKind::Mingguan->value,
            'amount' => $amount,
            'week_number' => $report->week_number,
        ], $actor);
        $this->notifyFinance($payment);

        return $payment;
    }

    /** Finance pays an approved payment from one of the company's accounts — PENGELUARAN "Jasa Pengawas". */
    public function pay(SupervisorPayment $payment, int $bankAccountId, User $actor): SupervisorPayment
    {
        return DB::transaction(function () use ($payment, $bankAccountId, $actor) {
            $payment = SupervisorPayment::with(['project:id,name', 'supervisor:id,name', 'weeklyReport:id,week_number,sequence'])->lockForUpdate()->findOrFail($payment->id);

            if ($payment->status !== SupervisorPaymentStatus::DisetujuiPm) {
                throw ValidationException::withMessages(['payment' => $payment->status === SupervisorPaymentStatus::Dibayar
                    ? 'Pembayaran ini sudah dicatat.'
                    : 'Pembayaran ini belum disetujui PM.']);
            }

            $transaction = $this->financeTransactionService->create([
                'project_id' => $payment->project_id,
                'bank_account_id' => $bankAccountId,
                'type' => FinanceTransactionType::Expense->value,
                'kategori' => FinanceCategory::JasaPengawas->value,
                'amount' => $payment->amount,
                'description' => "Upah Tukang {$payment->label} — {$payment->supervisor?->name}",
                'reference_id' => $payment->id,
                'date' => now('Asia/Jakarta')->toDateString(),
            ], $actor, 'finance.supervisor_paid');

            $payment->update([
                'status' => SupervisorPaymentStatus::Dibayar->value,
                'paid_by' => $actor->id,
                'paid_at' => now(),
                'bank_account_id' => $bankAccountId,
                'finance_transaction_id' => $transaction->id,
            ]);

            $this->notificationService->notifyMany(
                [$payment->supervisor],
                NotificationType::SupervisorPaymentPaid,
                'Upah Tukang Dibayar',
                "{$payment->label} sebesar Rp ".$this->rupiah($payment->amount).' sudah dibayar Finance.',
                ['project_id' => $payment->project_id],
            );

            return $payment;
        });
    }

    /**
     * Fee figures for the Eksekusi tab (null without a fee).
     *
     * @return array{total_amount: float, dp_percent: float, dp_amount: float, committed: float, paid: float, remaining: float}|null
     */
    public function summary(Project $project): ?array
    {
        $fee = $project->supervisorFee()->first();

        if (! $fee) {
            return null;
        }

        return [
            'total_amount' => (float) $fee->total_amount,
            'dp_percent' => (float) $fee->dp_percent,
            'dp_amount' => $fee->dpAmount(),
            'committed' => $fee->committedAmount(),
            'paid' => $fee->paidAmount(),
            'remaining' => $fee->remainingAmount(),
        ];
    }

    /**
     * PERSIAPAN → PRODUKSI: Logistics orders the materials, the team and the
     * Pengawas start — and so does the first timeline (Sprint 28 K9).
     */
    private function startProduction(Project $project, User $actor): void
    {
        $this->phaseService->advance($project, ProjectPhase::Persiapan, ProjectPhase::Produksi, $actor);
        $this->milestoneService->startFirstPending($project);

        $this->notificationService->notifyMany(
            User::role('LOGISTICS')->where('is_active', true)->get()
                ->concat($project->supervisors()->get())
                ->push($project->teamLead, $project->assistantPm)
                ->filter(fn (?User $user) => $user && $user->id !== $actor->id)
                ->unique('id'),
            NotificationType::ProductionStarted,
            'Start Produksi',
            "Produksi proyek \"{$project->name}\" dimulai — Logistik: pesan bahan sesuai rencana material proyek.",
            ['project_id' => $project->id],
        );
    }

    /**
     * @param  list<array{label: string, amount: float}>|null  $breakdown  Sprint 29 Sub 09 — split per Kepala Tim
     * @param  bool  $notifyPm  false when the weekly report's own notification carries it
     */
    private function createRequest(
        Project $project,
        SupervisorFee $fee,
        SupervisorPaymentKind $kind,
        float $amount,
        User $actor,
        ?WeeklyReport $report = null,
        ?array $breakdown = null,
        bool $notifyPm = true,
    ): SupervisorPayment {
        $this->ensureWithinFee($fee, $amount);

        $payment = $fee->payments()->create([
            'project_id' => $project->id,
            // Sprint 29 Sub 06 (A4) — the Pengawas who asked.
            'supervisor_id' => $project->isSupervisedBy($actor) ? $actor->id : $project->supervisors()->value('users.id'),
            'kind' => $kind->value,
            'amount' => $amount,
            'breakdown' => $breakdown ?: null,
            'weekly_report_id' => $report?->id,
            'status' => SupervisorPaymentStatus::Diajukan->value,
            'requested_by' => $actor->id,
            'requested_at' => now(),
        ]);
        $payment->setRelation('project', $project);
        $payment->setRelation('weeklyReport', $report);
        $this->auditLogService->record('supervisor_payment.requested', $payment, null, [
            'kind' => $kind->value,
            'amount' => $amount,
            'weekly_report_id' => $report?->id,
            'breakdown' => $breakdown ?: null,
        ], $actor);

        if (! $notifyPm) {
            return $payment;
        }

        $this->notificationService->notifyMany(
            [$project->pm],
            NotificationType::SupervisorPaymentRequested,
            $kind === SupervisorPaymentKind::Dp ? 'DP Upah Tukang Menunggu Persetujuan Anda' : 'Pengajuan Upah Tukang Menunggu Persetujuan Anda',
            "{$actor->name} mengajukan {$payment->label} sebesar Rp ".$this->rupiah($amount).($kind === SupervisorPaymentKind::Dp
                ? ' — bila Anda setujui, Produksi dimulai. Setujui atau tolak di kartu Upah Tukang (tab Tahapan).'
                : ' — setujui atau tolak di kartu Upah Tukang (tab Tahapan).'),
            ['project_id' => $project->id],
        );

        return $payment;
    }

    /**
     * Split per Kepala Tim — only for a project with wages per RAB item.
     *
     * @param  'total'|'progress'  $basis
     * @return list<array{label: string, amount: float}>|null
     */
    private function shares(Project $project, float $amount, string $basis, float $alreadyPaid): ?array
    {
        if (! $this->workItemService->hasWorkItems($project)) {
            return null;
        }

        return $this->workItemService->teamShares($project, $amount, $basis, $alreadyPaid) ?: null;
    }

    private function feeOf(Project $project): SupervisorFee
    {
        return $project->supervisorFee()->first()
            ?? throw ValidationException::withMessages(['fee' => 'Nilai upah tukang belum ditetapkan di Technical Meeting.']);
    }

    private function ensureWithinFee(SupervisorFee $fee, float $amount, ?int $exceptPaymentId = null): void
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nominal harus lebih dari 0.']);
        }

        $left = round((float) $fee->total_amount - $fee->committedAmount($exceptPaymentId), 2);

        if ($amount - $left > 0.001) {
            throw ValidationException::withMessages([
                'amount' => 'Nominal melebihi sisa upah tukang (sisa Rp '.$this->rupiah(max(0, $left)).').',
            ]);
        }
    }

    private function notifySupervisor(SupervisorPayment $payment, string $title, string $message): void
    {
        $this->notificationService->notifyMany(
            [$payment->supervisor],
            NotificationType::SupervisorPaymentDecided,
            $title,
            $message,
            ['project_id' => $payment->project_id],
        );
    }

    private function notifyFinance(SupervisorPayment $payment): void
    {
        $this->notificationService->notifyRoles(
            ['FINANCE'],
            NotificationType::SupervisorPaymentToPay,
            "Bayar Upah Tukang: {$payment->label}",
            "Rp {$this->rupiah($payment->amount)} untuk {$payment->supervisor?->name} — disetujui PM, siap dibayar.",
            ['supervisor_payment_id' => $payment->id, 'project_id' => $payment->project_id],
        );
    }

    private function rupiah(float|string|null $amount): string
    {
        return number_format((float) $amount, 0, ',', '.');
    }
}
