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

        return OvertimeRequest::create([
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
            }

            return $overtime->fresh();
        });
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
