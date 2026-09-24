<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\FamilyGatheringFund;
use App\Models\Penalty;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PRD §6.5 "Logika Penalti Harian" — runs from DailyPenaltyJob
 * (routes/console.php, Mon–Sat 21:00 WIB). One penalty per staff per day
 * (not per task): the pseudocode checks "apakah tukang sudah isi daily
 * form hari ini" at the staff level, not once per active task.
 */
class PenaltyService
{
    public const AMOUNT = 50000;

    public function __construct(
        private NotificationService $notificationService,
        private AuditLogService $auditLogService,
    ) {}

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

        $financeUser = User::role('FINANCE')->first() ?? User::role('CEO')->first();

        $created = 0;

        foreach ($this->staffMissingDailyForm($date) as $staff) {
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

                // PRD §9.4 "penjatuhan penalti" — actor null = Sistem (scheduled job).
                $this->auditLogService->record(
                    'penalty.issued',
                    $penalty,
                    null,
                    ['staff' => $staff->name, 'type' => $penalty->type, 'amount' => $penalty->amount, 'date_occurred' => $today],
                );

                // PRD §4.9 / §6.5 "Penalti dijatuhkan → Tukang + Finance".
                $this->notificationService->notify(
                    $staff,
                    'penalty_issued',
                    'Penalti Form Harian',
                    'Anda dikenakan penalti Rp 50.000 karena form harian tanggal '
                        .Carbon::parse($today)->translatedFormat('d F Y').' belum diisi sampai 21:00 WIB.',
                    ['penalty_id' => $penalty->id],
                );

                $this->notificationService->notifyRoles(
                    ['FINANCE'],
                    'penalty_issued',
                    'Penalti Dijatuhkan',
                    "{$staff->name} dikenakan penalti Rp 50.000 (form harian {$today} kosong) — masuk Dana Family Gathering.",
                    ['penalty_id' => $penalty->id],
                );
            });

            $created++;
        }

        return $created;
    }

    /**
     * PRD §4.9 "Form daily task belum diisi → Tukang yang bersangkutan" —
     * a warning ahead of the 21:00 penalty run (DailyFormReminderJob, see
     * routes/console.php). Idempotent per day.
     */
    public function sendDailyFormReminders(?Carbon $date = null): int
    {
        $sent = 0;

        foreach ($this->staffMissingDailyForm($date ?? now('Asia/Jakarta')) as $staff) {
            if ($this->notificationService->alreadySentToday($staff, 'daily_form_reminder', 'staff_id', $staff->id)) {
                continue;
            }

            $this->notificationService->notify(
                $staff,
                'daily_form_reminder',
                'Form Harian Belum Diisi',
                'Isi form harian sebelum 21:00 WIB untuk menghindari penalti Rp 50.000.',
                ['staff_id' => $staff->id],
            );

            $sent++;
        }

        return $sent;
    }

    /**
     * Field Staff with at least one not-DONE task and no daily form for
     * `$date` — the population both the reminder and the penalty act on.
     *
     * @return Collection<int, User>
     */
    public function staffMissingDailyForm(Carbon $date): Collection
    {
        return User::role('FIELD_STAFF')
            ->where('is_active', true)
            ->whereHas('assignedTasks', fn ($query) => $query->where('status', '!=', TaskStatus::Done->value))
            // whereDate(), not where() — `work_date` is cast to 'date' and
            // stored with a midnight time component, so a bare string
            // comparison never matches (see DailyTaskFormService::store()'s
            // comment) — this exact bug once made the job penalize the
            // same staff on every run.
            ->whereDoesntHave('dailyTaskForms', fn ($query) => $query->whereDate('work_date', $date->toDateString()))
            ->get();
    }
}
