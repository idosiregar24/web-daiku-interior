<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\DailyTaskForm;
use App\Models\FamilyGatheringFund;
use App\Models\Penalty;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PRD §6.5 "Logika Penalti Harian" — runs from DailyPenaltyJob
 * (routes/console.php, Mon–Sat 21:00 WIB). One penalty per staff per day
 * (not per task): the pseudocode checks "apakah tukang sudah isi daily
 * form hari ini" at the staff level, not once per active task.
 */
class PenaltyService
{
    private const AMOUNT = 50000;

    /**
     * Idempotent — re-running for the same date never double-penalizes
     * (backend-standards.md §5: "job yang re-run pada hari yang sama
     * tidak boleh membuat penalti dobel"), checked per-staff before
     * creating anything.
     */
    public function runDailyCheck(?Carbon $date = null): int
    {
        $date ??= now('Asia/Jakarta');
        $today = $date->toDateString();

        $staffWithActiveTasks = User::role('FIELD_STAFF')
            ->whereHas('assignedTasks', fn ($query) => $query->where('status', '!=', TaskStatus::Done->value))
            ->get();

        $financeUser = User::role('FINANCE')->first() ?? User::role('CEO')->first();

        $created = 0;

        foreach ($staffWithActiveTasks as $staff) {
            // whereDate(), not where() — `work_date`/`date_occurred` are
            // cast to 'date' and store with a midnight time component, so
            // a bare string comparison never matches (see
            // DailyTaskFormService::store()'s comment for the full
            // explanation) — this exact bug would have made the job
            // penalize the same staff every single run.
            $submittedToday = DailyTaskForm::where('staff_id', $staff->id)
                ->whereDate('work_date', $today)
                ->exists();

            if ($submittedToday) {
                continue;
            }

            $alreadyPenalized = Penalty::where('staff_id', $staff->id)
                ->where('type', 'DAILY_FORM_MISSING')
                ->whereDate('date_occurred', $today)
                ->exists();

            if ($alreadyPenalized) {
                continue;
            }

            DB::transaction(function () use ($staff, $today, $financeUser) {
                $penalty = Penalty::create([
                    'staff_id' => $staff->id,
                    'type' => 'DAILY_FORM_MISSING',
                    'amount' => self::AMOUNT,
                    'date_occurred' => $today,
                ]);

                if ($financeUser) {
                    FamilyGatheringFund::create([
                        'type' => 'INCOME',
                        'amount' => self::AMOUNT,
                        'description' => "Penalti form harian belum diisi — {$staff->name} ({$today})",
                        'source_penalty_id' => $penalty->id,
                        'recorded_by' => $financeUser->id,
                    ]);
                }

                // TODO: PRD §6.5 also wants notifications to the staff and
                // Finance here — deferred, no notification infra exists
                // yet (see .claude/plan/README.md's Week 3 CRM follow-up
                // reminder note for the same deferral reasoning).
            });

            $created++;
        }

        return $created;
    }
}
