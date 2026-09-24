<?php

namespace App\Services;

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Enums\OvertimeStatus;
use App\Models\FinanceTransaction;
use App\Models\OvertimeRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PRD §4.5/§6.6 "Alur Pengajuan Lembur" — sequential PM→Finance approval,
 * same "state = last completed gate" + single entry-point-per-gate
 * pattern already used for Quotation's dual approval (see
 * QuotationService's docblock). See OvertimeStatus's docblock for why
 * PENDING_FINANCE is skipped.
 */
class OvertimeService
{
    public function __construct(
        private NotificationService $notificationService,
        private AuditLogService $auditLogService,
    ) {}

    /**
     * PRD §4.5 "Lembur hanya bisa diajukan untuk hari yang sudah berlalu
     * atau berjalan" — work_date can't be in the future.
     */
    public function create(array $data, User $actor): OvertimeRequest
    {
        if (now('Asia/Jakarta')->toDateString() < $data['work_date']) {
            throw ValidationException::withMessages([
                'work_date' => 'Lembur hanya bisa diajukan untuk hari yang sudah berlalu atau berjalan.',
            ]);
        }

        $overtime = OvertimeRequest::create([
            'staff_id' => $actor->id,
            'project_id' => $data['project_id'],
            'task_id' => $data['task_id'] ?? null,
            'hours' => $data['hours'],
            'rate_per_hour' => $data['rate_per_hour'],
            'total_amount' => $data['hours'] * $data['rate_per_hour'],
            'work_date' => $data['work_date'],
            'reason' => $data['reason'],
            'status' => OvertimeStatus::Pending->value,
        ]);

        // PRD §4.9 "Pengajuan lembur masuk → PM proyek tukang tersebut".
        $this->notificationService->notifyMany(
            [$overtime->project->pm],
            'overtime_submitted',
            'Pengajuan Lembur Baru',
            "{$actor->name} mengajukan lembur {$overtime->hours} jam ({$this->workDate($overtime)}) di proyek \"{$overtime->project->name}\".",
            ['overtime_id' => $overtime->id, 'project_id' => $overtime->project_id],
        );

        return $overtime;
    }

    public function pmDecision(OvertimeRequest $overtime, string $decision, User $actor, ?string $note = null): OvertimeRequest
    {
        if ($overtime->status !== OvertimeStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => 'Pengajuan ini sudah diproses.',
            ]);
        }

        $this->guardDecision($decision, $note);

        $overtime->update([
            'status' => $decision === 'approve' ? OvertimeStatus::ApprovedPm->value : OvertimeStatus::Rejected->value,
            'pm_approved_by' => $actor->id,
            'pm_approved_at' => now(),
            'reject_note' => $decision === 'reject' ? $note : null,
        ]);

        $this->audit($overtime, 'pm', $decision, OvertimeStatus::Pending, $note, $actor);

        if ($decision === 'approve') {
            // PRD §4.9 "Lembur approve oleh PM → Tukang + Finance".
            $this->notifyStaff($overtime, 'overtime_approved_pm', 'Lembur Disetujui PM', 'disetujui PM dan menunggu approval Finance.');
            $this->notificationService->notifyRoles(
                ['FINANCE'],
                'overtime_approved_pm',
                'Lembur Menunggu Approval Finance',
                "Lembur {$overtime->staff->name} {$overtime->hours} jam ({$this->workDate($overtime)}) disetujui PM — menunggu approval Anda.",
                ['overtime_id' => $overtime->id, 'project_id' => $overtime->project_id],
            );
        } else {
            $this->notifyStaff($overtime, 'overtime_rejected', 'Lembur Ditolak', "ditolak PM: {$note}");
        }

        return $overtime->fresh();
    }

    /**
     * Finance's approval both closes their gate and records the
     * FinanceTransaction expense (PRD §6.6 "Finance catat sebagai EXPENSE
     * (OVERTIME_PAY)") in the same transaction — never one without the
     * other.
     */
    public function financeDecision(OvertimeRequest $overtime, string $decision, User $actor, ?string $note = null): OvertimeRequest
    {
        if ($overtime->status !== OvertimeStatus::ApprovedPm) {
            throw ValidationException::withMessages([
                'status' => 'Pengajuan ini menunggu approval PM terlebih dahulu.',
            ]);
        }

        $this->guardDecision($decision, $note);

        return DB::transaction(function () use ($overtime, $decision, $actor, $note) {
            $overtime->update([
                'status' => $decision === 'approve' ? OvertimeStatus::ApprovedFinance->value : OvertimeStatus::Rejected->value,
                'finance_approved_by' => $actor->id,
                'finance_approved_at' => now(),
                'reject_note' => $decision === 'reject' ? $note : $overtime->reject_note,
            ]);

            $this->audit($overtime, 'finance', $decision, OvertimeStatus::ApprovedPm, $note, $actor);

            if ($decision === 'approve') {
                FinanceTransaction::create([
                    'project_id' => $overtime->project_id,
                    'type' => FinanceTransactionType::Expense->value,
                    'kategori' => FinanceCategory::LemburBonus->value,
                    'amount' => $overtime->total_amount,
                    'description' => "Lembur {$overtime->staff->name} — {$overtime->work_date->toDateString()} ({$overtime->hours} jam)",
                    'reference_id' => $overtime->id,
                    'date' => now()->toDateString(),
                    'created_by' => $actor->id,
                ]);

                // PRD §4.9 "Lembur approve oleh Finance → Tukang" (table row
                // is implied by the §6.6 flow; CSV Sprint 5 lists it explicitly).
                $this->notifyStaff($overtime, 'overtime_approved_finance', 'Lembur Disetujui Finance', 'disetujui Finance dan dicatat untuk pembayaran.');
            } else {
                $this->notifyStaff($overtime, 'overtime_rejected', 'Lembur Ditolak', "ditolak Finance: {$note}");
            }

            return $overtime->fresh();
        });
    }

    /** PRD §9.4 — overtime approval releases money (Finance's step writes an EXPENSE), so both gates are audited. */
    private function audit(OvertimeRequest $overtime, string $gate, string $decision, OvertimeStatus $from, ?string $note, User $actor): void
    {
        $this->auditLogService->record(
            "overtime.{$gate}_".($decision === 'approve' ? 'approved' : 'rejected'),
            $overtime,
            ['status' => $from],
            ['status' => $overtime->status, 'hours' => $overtime->hours, 'total_amount' => $overtime->total_amount, 'note' => $note],
            $actor,
        );
    }

    private function notifyStaff(OvertimeRequest $overtime, string $type, string $title, string $outcome): void
    {
        $this->notificationService->notify(
            $overtime->staff,
            $type,
            $title,
            "Pengajuan lembur {$overtime->hours} jam ({$this->workDate($overtime)}) Anda {$outcome}",
            ['overtime_id' => $overtime->id, 'project_id' => $overtime->project_id],
        );
    }

    private function workDate(OvertimeRequest $overtime): string
    {
        return $overtime->work_date->translatedFormat('d F Y');
    }

    private function guardDecision(string $decision, ?string $note): void
    {
        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw ValidationException::withMessages(['decision' => 'Keputusan tidak valid.']);
        }

        if ($decision === 'reject' && ! $note) {
            throw ValidationException::withMessages(['note' => 'Catatan alasan reject wajib diisi.']);
        }
    }
}
