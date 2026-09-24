<?php

namespace App\Services;

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Enums\MilestoneStatus;
use App\Enums\TerminStatus;
use App\Models\FinanceTransaction;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Termin;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PRD §4.4/§4.7/§6.4 "Termin Schedule" / "Logika Termin Sabtu". PM
 * schedules (`create`), Finance marks paid (`markPaid`) — matches PRD
 * §7.1 "Finance – Termin" row (PM `C` only, Finance `RU`).
 */
class TerminService
{
    public function __construct(
        private NotificationService $notificationService,
        private AuditLogService $auditLogService,
    ) {}

    /**
     * PRD §6.4: "PM membuat termin: bebas tentukan persentase. Validasi:
     * total semua persentase termin = 100%. scheduledDate otomatis =
     * Sabtu terdekat setelah milestone.targetDate" — enforced as a
     * ceiling here (a project's termins may sum to less than 100% while
     * PM is still scheduling the rest, but never more).
     */
    public function create(Project $project, array $data): Termin
    {
        $existingTotal = (int) $project->termins()->sum('percentage');

        if ($existingTotal + (int) $data['percentage'] > 100) {
            throw ValidationException::withMessages([
                'percentage' => "Total persentase termin proyek ini sudah {$existingTotal}% — tidak boleh melebihi 100%.",
            ]);
        }

        $milestone = isset($data['milestone_id']) ? Milestone::find($data['milestone_id']) : null;
        $baseDate = $milestone?->target_date ?? now();

        $terminNumber = (int) $project->termins()->max('termin_number') + 1;

        return $project->termins()->create([
            'milestone_id' => $milestone?->id,
            'termin_number' => $terminNumber,
            'percentage' => $data['percentage'],
            'amount' => round($project->contract_value * $data['percentage'] / 100, 2),
            'scheduled_date' => $this->getNextSaturday(Carbon::parse($baseDate)),
            'status' => TerminStatus::Scheduled->value,
            'bank_account_id' => $data['bank_account_id'] ?? null,
        ]);
    }

    /**
     * PRD §4.9 "Termin overdue → Finance, CEO" — daily (TerminOverdueJob).
     * An unpaid termin past its Saturday moves to OVERDUE; that status
     * change is also the idempotency guard (already-OVERDUE termins are
     * never re-picked, so never re-notified). OVERDUE stays payable —
     * markPaid() only refuses PAID.
     */
    public function markOverdue(): int
    {
        $overdue = Termin::query()
            ->with('project:id,name')
            ->whereIn('status', [TerminStatus::Scheduled->value, TerminStatus::Invoiced->value])
            ->whereDate('scheduled_date', '<', now('Asia/Jakarta')->toDateString())
            ->get();

        foreach ($overdue as $termin) {
            $termin->update(['status' => TerminStatus::Overdue->value]);

            $this->notificationService->notifyRoles(
                ['FINANCE', 'CEO'],
                'termin_overdue',
                'Termin Overdue',
                "Termin #{$termin->termin_number} proyek \"{$termin->project->name}\" (Rp "
                    .number_format((float) $termin->amount, 0, ',', '.')
                    .") melewati jadwal {$termin->scheduled_date->translatedFormat('d F Y')} dan belum dibayar.",
                ['termin_id' => $termin->id, 'project_id' => $termin->project_id],
            );
        }

        return $overdue->count();
    }

    /** PRD §6.4 pseudocode, ported 1:1 (0=Minggu…6=Sabtu, same as JS `Date.getDay()`). */
    public function getNextSaturday(Carbon $fromDate): Carbon
    {
        $dayOfWeek = $fromDate->dayOfWeek;
        $daysUntilSaturday = $dayOfWeek === Carbon::SATURDAY ? 7 : (Carbon::SATURDAY - $dayOfWeek);

        return $fromDate->copy()->addDays($daysUntilSaturday);
    }

    /**
     * PRD §6.3 "Termin Sabtu unlocked (Finance bisa generate invoice)"
     * once the linked milestone is COMPLETED — a termin not tied to a
     * specific milestone (e.g. a plain DP termin) has no such gate.
     */
    public function markPaid(Termin $termin, User $actor): Termin
    {
        if ($termin->status === TerminStatus::Paid) {
            throw ValidationException::withMessages([
                'status' => 'Termin ini sudah dibayar.',
            ]);
        }

        if ($termin->milestone_id && $termin->milestone->status !== MilestoneStatus::Completed) {
            throw ValidationException::withMessages([
                'status' => 'Termin ini masih terkunci — milestone terkait belum COMPLETED (lolos QA).',
            ]);
        }

        return DB::transaction(function () use ($termin, $actor) {
            $oldStatus = $termin->status;
            $termin->update(['status' => TerminStatus::Paid->value, 'paid_at' => now()]);

            // PRD §9.4 "perubahan finance".
            $this->auditLogService->record(
                'finance.termin_paid',
                $termin,
                ['status' => $oldStatus],
                ['status' => $termin->status, 'amount' => $termin->amount, 'paid_at' => $termin->paid_at, 'bank_account_id' => $termin->bank_account_id],
                $actor,
            );

            FinanceTransaction::create([
                'project_id' => $termin->project_id,
                'bank_account_id' => $termin->bank_account_id,
                'type' => FinanceTransactionType::Income->value,
                'kategori' => ($termin->termin_number === 1 ? FinanceCategory::DownPayment : FinanceCategory::Termin)->value,
                'amount' => $termin->amount,
                'description' => "Pembayaran termin #{$termin->termin_number} — {$termin->project->name}",
                'reference_id' => $termin->id,
                'date' => now()->toDateString(),
                'created_by' => $actor->id,
            ]);

            return $termin->fresh();
        });
    }
}
