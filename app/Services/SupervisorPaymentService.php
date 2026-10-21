<?php

namespace App\Services;

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Enums\NotificationType;
use App\Enums\ProjectPhase;
use App\Enums\ProjectStatus;
use App\Enums\SupervisorPaymentKind;
use App\Enums\SupervisorPaymentStatus;
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

        $wagesMissing = $withTaskTitles
            ? $unpaid->take(self::MISSING_SHOWN)->map(fn ($task) => trim($task->title.' · '.(($task->planned_date ?? $task->due_date)?->format('d/m') ?? '')))->all()
            : [];

        return [
            [
                'key' => 'materials',
                'label' => 'Bahan',
                'done' => $hasMaterials,
                'detail' => $hasMaterials ? null : 'Belum ada rencana bahan.',
                'missing' => [],
                'more' => 0,
            ],
            [
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
            // Sprint 29 Sub 03 — no longer required at "TM Selesai"; the DP is
            // a share of it, so it is checked here (until Sub 08's upah tukang).
            [
                'key' => 'fee',
                'label' => 'Nilai jasa pengawas & DP % · PM',
                'done' => $hasFee,
                'detail' => $hasFee ? null : 'PM belum mengisi nilai jasa pengawas (kartu Technical Meeting).',
                'missing' => [],
                'more' => 0,
            ],
        ];
    }

    /** "Ajukan DP Pengawas" — the project's Pengawas, at PERSIAPAN, once the conditions are met. */
    public function requestDp(Project $project, User $actor): SupervisorPayment
    {
        return DB::transaction(function () use ($project, $actor) {
            $project = Project::lockForUpdate()->findOrFail($project->id);
            $this->phaseService->ensurePhase($project, ProjectPhase::Persiapan, 'DP pengawas diajukan saat proyek di tahap Persiapan (setelah TM selesai).');
            $fee = $this->feeOf($project);

            $missing = collect($this->dpRequirements($project))->reject(fn (array $row) => $row['done'])->pluck('label');

            if ($missing->isNotEmpty()) {
                throw ValidationException::withMessages(['dp' => 'Syarat DP belum lengkap: '.$missing->implode(', ').'.']);
            }

            if ($fee->payments()->where('kind', SupervisorPaymentKind::Dp->value)->whereIn('status', SupervisorPaymentStatus::committedValues())->exists()) {
                throw ValidationException::withMessages(['dp' => 'DP pengawas proyek ini sudah diajukan.']);
            }

            return $this->createRequest($project, $fee, SupervisorPaymentKind::Dp, $fee->dpAmount(), $actor);
        });
    }

    /** E3 — "Ajukan Pelunasan": the rest of the fee, once the project is COMPLETED. */
    public function requestSettlement(Project $project, User $actor): SupervisorPayment
    {
        return DB::transaction(function () use ($project, $actor) {
            $project = Project::lockForUpdate()->findOrFail($project->id);

            if ($project->status !== ProjectStatus::Completed) {
                throw ValidationException::withMessages(['settlement' => 'Pelunasan jasa pengawas diajukan setelah proyek selesai.']);
            }

            $fee = $this->feeOf($project);

            if ($fee->payments()->whereIn('status', [SupervisorPaymentStatus::Diajukan->value])->exists()) {
                throw ValidationException::withMessages(['settlement' => 'Masih ada pengajuan pembayaran pengawas yang menunggu PM.']);
            }

            $remaining = $fee->remainingAmount();

            if ($remaining <= 0) {
                throw ValidationException::withMessages(['settlement' => 'Jasa pengawas proyek ini sudah dibayar penuh.']);
            }

            return $this->createRequest($project, $fee, SupervisorPaymentKind::Pelunasan, $remaining, $actor);
        });
    }

    /**
     * The PM of the project decides a DIAJUKAN request. Approve: the amount
     * may be changed (never above what is left of the fee) → Finance's
     * queue; a DP approval starts production. Reject: a note is required;
     * the Pengawas may ask again.
     */
    public function decide(SupervisorPayment $payment, bool $approve, ?float $amount, ?string $note, User $actor): SupervisorPayment
    {
        return DB::transaction(function () use ($payment, $approve, $amount, $note, $actor) {
            $payment = SupervisorPayment::lockForUpdate()->findOrFail($payment->id);
            $project = Project::lockForUpdate()->findOrFail($payment->project_id);

            if ($payment->status !== SupervisorPaymentStatus::Diajukan) {
                throw ValidationException::withMessages(['payment' => 'Pengajuan ini sudah diputuskan.']);
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
                $this->notifySupervisor($payment, 'Pengajuan Ditolak PM', "Pengajuan {$payment->label} ditolak {$actor->name}: ".trim($note));

                return $payment;
            }

            $amount = round($amount ?? (float) $payment->amount, 2);
            $this->ensureWithinFee($payment->fee, $amount, $payment->id);

            $payment->update([
                'amount' => $amount,
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

            $this->notifySupervisor($payment, 'Pengajuan Disetujui PM', "Pengajuan {$payment->label} (Rp ".$this->rupiah($amount).") disetujui {$actor->name} — menunggu dibayar Finance.");
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
            'supervisor_id' => $report->supervisor_id ?? $project->supervisor_id,
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
            $payment = SupervisorPayment::with(['project:id,name', 'supervisor:id,name', 'weeklyReport:id,week_number'])->lockForUpdate()->findOrFail($payment->id);

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
                'description' => "Jasa Pengawas {$payment->label} — {$payment->supervisor?->name}",
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
                'Jasa Pengawas Dibayar',
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
                ->push($project->supervisor, $project->teamLead, $project->assistantPm)
                ->filter(fn (?User $user) => $user && $user->id !== $actor->id),
            NotificationType::ProductionStarted,
            'Start Produksi',
            "Produksi proyek \"{$project->name}\" dimulai — Logistik: pesan bahan sesuai rencana material proyek.",
            ['project_id' => $project->id],
        );
    }

    private function createRequest(Project $project, SupervisorFee $fee, SupervisorPaymentKind $kind, float $amount, User $actor): SupervisorPayment
    {
        $this->ensureWithinFee($fee, $amount);

        $payment = $fee->payments()->create([
            'project_id' => $project->id,
            'supervisor_id' => $project->supervisor_id,
            'kind' => $kind->value,
            'amount' => $amount,
            'status' => SupervisorPaymentStatus::Diajukan->value,
            'requested_by' => $actor->id,
            'requested_at' => now(),
        ]);
        $payment->setRelation('project', $project);
        $this->auditLogService->record('supervisor_payment.requested', $payment, null, [
            'kind' => $kind->value,
            'amount' => $amount,
        ], $actor);

        $this->notificationService->notifyMany(
            [$project->pm],
            NotificationType::SupervisorPaymentRequested,
            'Pengajuan Jasa Pengawas',
            "{$actor->name} mengajukan {$payment->label} sebesar Rp ".$this->rupiah($amount).' — setujui atau tolak di tab Eksekusi.',
            ['project_id' => $project->id],
        );

        return $payment;
    }

    private function feeOf(Project $project): SupervisorFee
    {
        return $project->supervisorFee()->first()
            ?? throw ValidationException::withMessages(['fee' => 'Nilai jasa pengawas belum ditetapkan di Technical Meeting.']);
    }

    private function ensureWithinFee(SupervisorFee $fee, float $amount, ?int $exceptPaymentId = null): void
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nominal harus lebih dari 0.']);
        }

        $left = round((float) $fee->total_amount - $fee->committedAmount($exceptPaymentId), 2);

        if ($amount - $left > 0.001) {
            throw ValidationException::withMessages([
                'amount' => 'Nominal melebihi sisa jasa pengawas (sisa Rp '.$this->rupiah(max(0, $left)).').',
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
            "Bayar Pengawas: {$payment->label}",
            "Rp {$this->rupiah($payment->amount)} untuk {$payment->supervisor?->name} — disetujui PM, siap dibayar.",
            ['supervisor_payment_id' => $payment->id, 'project_id' => $payment->project_id],
        );
    }

    private function rupiah(float|string|null $amount): string
    {
        return number_format((float) $amount, 0, ',', '.');
    }
}
