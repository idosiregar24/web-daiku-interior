<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\ProjectPhase;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskScheduleChange;
use App\Models\User;
use App\Support\DailyFormSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 24 D6/D11/D13 — production as the numbers show it: progress from
 * actual activity, the 70 % moment (PM told once, "Setujui Berangkat ke
 * Lokasi" unlocked), the daily "H-30%" reminder to the PM, and the
 * target-vs-actual table per Tukang per day.
 */
class ProductionService
{
    public function __construct(
        private NotificationService $notificationService,
        private AuditLogService $auditLogService,
        private ProjectPhaseService $phaseService,
    ) {}

    public static function departureThreshold(): int
    {
        return (int) config('daiku.execution.site_departure_progress', 70);
    }

    /**
     * Called whenever a task turns DONE: the first time a producing
     * project's actual activity reaches the threshold, its PM is told
     * "siap berangkat ke lokasi" (once — progress_70_notified_at).
     */
    public function checkProgress(Project $project): void
    {
        if ($project->currentPhase() !== ProjectPhase::Produksi || $project->progress_70_notified_at !== null) {
            return;
        }

        $progress = $project->actualProgress();

        if ($progress < self::departureThreshold()) {
            return;
        }

        $project->update(['progress_70_notified_at' => now()]);

        $this->notificationService->notifyMany(
            [$project->pm],
            NotificationType::ProjectProgressReached,
            'Siap Berangkat ke Lokasi',
            "Actual activity proyek \"{$project->name}\" sudah {$progress}% — setujui berangkat ke lokasi di tab Eksekusi.",
            ['project_id' => $project->id],
        );
    }

    /** "Setujui Berangkat ke Lokasi" — the project's PM, at PRODUKSI, progress ≥ threshold → PEMASANGAN. */
    public function approveDeparture(Project $project, User $actor): Project
    {
        return DB::transaction(function () use ($project, $actor) {
            $project = Project::lockForUpdate()->findOrFail($project->id);
            $this->phaseService->ensurePhase($project, ProjectPhase::Produksi, 'Berangkat ke lokasi hanya bisa disetujui saat proyek di tahap Produksi.');

            $progress = $project->actualProgress();

            if ($progress < self::departureThreshold()) {
                throw ValidationException::withMessages([
                    'departure' => "Actual activity baru {$progress}% — berangkat ke lokasi terbuka mulai ".self::departureThreshold().'%.',
                ]);
            }

            $project->update(['departure_approved_by' => $actor->id, 'departure_approved_at' => now()]);
            $this->phaseService->advance($project, ProjectPhase::Produksi, ProjectPhase::Pemasangan, $actor);

            $this->notificationService->notifyMany(
                User::role('LOGISTICS')->where('is_active', true)->get()
                    ->push($project->supervisor, $project->teamLead, $project->assistantPm)
                    ->filter(fn (?User $user) => $user && $user->id !== $actor->id),
                NotificationType::SiteDepartureApproved,
                'Berangkat ke Lokasi',
                "{$actor->name} menyetujui tim proyek \"{$project->name}\" berangkat ke lokasi (actual activity {$progress}%). Pengawas: cek finishing di lokasi.",
                ['project_id' => $project->id],
            );

            return $project;
        });
    }

    /**
     * D13 "H-30%": from the day `deadline_warning_ratio` of the project's
     * duration has passed (start → Tanggal Selesai, both counted — a
     * 10-day project warns from its 7th day) until it is closed.
     */
    public function deadlineWarningFrom(Project $project): ?Carbon
    {
        if (! $project->start_date || ! $project->end_date || $project->end_date->lt($project->start_date)) {
            return null;
        }

        $days = (int) $project->start_date->diffInDays($project->end_date) + 1;
        $offset = max(0, (int) ceil($days * (float) config('daiku.execution.deadline_warning_ratio', 0.7)) - 1);

        return $project->start_date->copy()->addDays($offset)->startOfDay();
    }

    /** DeadlineReminderJob — every day, idempotent per project per day. Returns how many PMs were told. */
    public function sendDeadlineReminders(?Carbon $today = null): int
    {
        $today = ($today ?? DailyFormSchedule::now())->copy()->startOfDay();
        $sent = 0;

        Project::query()
            ->active()
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->with('pm')
            ->each(function (Project $project) use ($today, &$sent) {
                $from = $this->deadlineWarningFrom($project);

                if (! $from || $today->lt($from) || ! $project->pm?->is_active
                    || $this->notificationService->alreadySentToday($project->pm, NotificationType::ProjectDeadlineNear, 'project_id', $project->id)) {
                    return;
                }

                $daysLeft = (int) $today->diffInDays($project->end_date->copy()->startOfDay(), false);
                $when = $daysLeft > 0 ? "sisa {$daysLeft} hari" : ($daysLeft === 0 ? 'selesai hari ini' : 'lewat '.abs($daysLeft).' hari');

                $this->notificationService->notify(
                    $project->pm,
                    NotificationType::ProjectDeadlineNear,
                    'Proyek Mendekati Tanggal Selesai',
                    "Proyek \"{$project->name}\" {$when} (Tanggal Selesai {$project->end_date->translatedFormat('d M Y')}) — actual activity {$project->actualProgress()}%.",
                    ['project_id' => $project->id],
                );
                $sent++;
            });

        return $sent;
    }

    /**
     * D6/D7 — "Target vs Actual" per Tukang per day: target = daily targets
     * first planned for that day, actual = targets finished that day,
     * rolled = targets that rolled away from that day unfinished.
     *
     * @return array{days: list<string>, rows: list<array{staff_id: int, name: string, cells: array<string, array{target: int, actual: int, rolled: int}>, target: int, actual: int}>}
     */
    public function targetVsActual(Project $project, Carbon $from, Carbon $to): array
    {
        $days = [];
        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            if (DailyFormSchedule::isWorkDay($day)) {
                $days[] = $day->toDateString();
            }
        }

        $tasks = Task::query()
            ->where('project_id', $project->id)
            ->with('assignee:id,name')
            ->get(['id', 'assignee_id', 'planned_date', 'due_date', 'status', 'completed_at']);
        $rolled = TaskScheduleChange::query()
            ->whereIn('task_id', $tasks->pluck('id'))
            ->where('kind', TaskScheduleChange::ROLLOVER)
            ->whereDate('from_date', '>=', $from->toDateString())
            ->whereDate('from_date', '<=', $to->toDateString())
            ->get(['task_id', 'from_date']);
        $assigneeOf = $tasks->pluck('assignee_id', 'id');

        $rows = [];
        $blank = fn () => array_fill_keys($days, ['target' => 0, 'actual' => 0, 'rolled' => 0]);

        foreach ($tasks as $task) {
            $staffId = (int) $task->assignee_id;
            $rows[$staffId] ??= ['staff_id' => $staffId, 'name' => $task->assignee?->name ?? 'Tukang', 'cells' => $blank()];
            $planned = ($task->planned_date ?? $task->due_date)?->toDateString();

            if ($planned && isset($rows[$staffId]['cells'][$planned])) {
                $rows[$staffId]['cells'][$planned]['target']++;
            }

            $done = $task->status === TaskStatus::Done && $task->completed_at
                ? $task->completed_at->copy()->timezone(DailyFormSchedule::TIMEZONE)->toDateString()
                : null;

            if ($done && isset($rows[$staffId]['cells'][$done])) {
                $rows[$staffId]['cells'][$done]['actual']++;
            }
        }

        foreach ($rolled as $change) {
            $staffId = (int) ($assigneeOf[$change->task_id] ?? 0);
            $day = $change->from_date->toDateString();

            if (isset($rows[$staffId]['cells'][$day])) {
                $rows[$staffId]['cells'][$day]['rolled']++;
            }
        }

        $rows = collect($rows)
            ->map(fn (array $row) => [
                ...$row,
                'target' => array_sum(array_column($row['cells'], 'target')),
                'actual' => array_sum(array_column($row['cells'], 'actual')),
            ])
            ->sortBy('name')
            ->values()
            ->all();

        return ['days' => $days, 'rows' => $rows];
    }
}
