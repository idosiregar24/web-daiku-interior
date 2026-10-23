<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\ProjectPhase;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskScheduleChange;
use App\Models\User;
use App\Support\DailyFormSchedule;
use App\Support\Quantity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 24 D6/D11/D13 — production as the numbers show it: the 70 %
 * moment (PM told once, "Setujui Berangkat ke Lokasi" unlocked), the daily
 * "H-30%" reminder to the PM, and the target-vs-actual table per Tukang per
 * day. Sprint 29 Sub 10 (B7) — the project progress is
 * Project::actualProgress(): the progres berbobot of the pengecekan for a
 * project with items with a wage, actual activity otherwise; the Activity
 * Target table below stays actual activity.
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
     * "progres berbobot 67,7%" / "actual activity 80%" — the progress as the
     * messages name it.
     *
     * @param  array{progress: int|float|null, basis: string}  $figure  Project::progressFigure() / listedProgress()
     */
    public static function progressText(array $figure): string
    {
        return ($figure['basis'] === Project::PROGRESS_WEIGHTED ? 'progres berbobot ' : 'actual activity ').Quantity::format($figure['progress'] ?? 0).'%';
    }

    /**
     * Called whenever the progress may have moved — a task turning DONE, a
     * pengecekan sent (Sprint 29 Sub 10): the first time a producing
     * project's progress reaches the threshold, its PM is told "siap
     * berangkat ke lokasi" (once — progress_70_notified_at).
     */
    public function checkProgress(Project $project): void
    {
        if ($project->currentPhase() !== ProjectPhase::Produksi || $project->progress_70_notified_at !== null) {
            return;
        }

        $figure = $project->progressFigure();

        if ($figure['progress'] < self::departureThreshold()) {
            return;
        }

        $project->update(['progress_70_notified_at' => now()]);

        $this->notificationService->notifyMany(
            [$project->pm],
            NotificationType::ProjectProgressReached,
            'Siap Berangkat ke Lokasi',
            'Proyek "'.$project->name.'" sudah '.self::progressText($figure).' — setujui berangkat ke lokasi di tab Tahapan.',
            ['project_id' => $project->id],
        );
    }

    /** "Setujui Berangkat ke Lokasi" — the project's PM, at PRODUKSI, progress ≥ threshold → PEMASANGAN. */
    public function approveDeparture(Project $project, User $actor): Project
    {
        return DB::transaction(function () use ($project, $actor) {
            $project = Project::lockForUpdate()->findOrFail($project->id);
            $this->phaseService->ensurePhase($project, ProjectPhase::Produksi, 'Berangkat ke lokasi hanya bisa disetujui saat proyek di tahap Produksi.');

            $figure = $project->progressFigure();

            if ($figure['progress'] < self::departureThreshold()) {
                throw ValidationException::withMessages([
                    'departure' => 'Proyek baru '.self::progressText($figure).' — berangkat ke lokasi terbuka mulai '.self::departureThreshold().'%.',
                ]);
            }

            $project->update(['departure_approved_by' => $actor->id, 'departure_approved_at' => now()]);
            $this->phaseService->advance($project, ProjectPhase::Produksi, ProjectPhase::Pemasangan, $actor);

            $this->notificationService->notifyMany(
                User::role('LOGISTICS')->where('is_active', true)->get()
                    ->concat($project->supervisors()->get())
                    ->push($project->teamLead, $project->assistantPm)
                    ->filter(fn (?User $user) => $user && $user->id !== $actor->id),
                NotificationType::SiteDepartureApproved,
                'Berangkat ke Lokasi',
                "{$actor->name} menyetujui tim proyek \"{$project->name}\" berangkat ke lokasi (".self::progressText($figure).'). Pengawas: cek finishing di lokasi.',
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
            ->withActivityCounts()
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
                    "Proyek \"{$project->name}\" {$when} (Tanggal Selesai {$project->end_date->translatedFormat('d M Y')}) — ".self::progressText($project->listedProgress()).'.',
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

        ['targets' => $targets, 'rolled' => $rolled] = $this->activityFacts($project, withStaff: true);

        $rows = [];
        $blank = fn () => array_fill_keys($days, ['target' => 0, 'actual' => 0, 'rolled' => 0]);

        foreach ($targets as $target) {
            $staffId = $target['staff_id'];
            $rows[$staffId] ??= ['staff_id' => $staffId, 'name' => $target['staff_name'] ?? 'Tukang', 'cells' => $blank()];

            if ($target['target_on'] && isset($rows[$staffId]['cells'][$target['target_on']])) {
                $rows[$staffId]['cells'][$target['target_on']]['target']++;
            }

            if ($target['done_on'] && isset($rows[$staffId]['cells'][$target['done_on']])) {
                $rows[$staffId]['cells'][$target['done_on']]['actual']++;
            }
        }

        foreach ($rolled as $change) {
            if (isset($rows[$change['staff_id']]['cells'][$change['day']])) {
                $rows[$change['staff_id']]['cells'][$change['day']]['rolled']++;
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

    /**
     * Sprint 25 — "Activity Target": the whole project, one row per working
     * day (grouped per week): daily targets planned that day vs targets
     * finished that day, plus both cumulated over every target the project
     * has now — so the last actual figure is Project::activityProgress().
     * Numbers only: no task title, no Tukang name (PRD §4.6).
     *
     * @return array{today: string, summary: array{total: int, done: int, progress: int, planned_to_date: int, target_to_date: int, gap: int, rolled: int}, weeks: list<array<string, mixed>>}
     */
    public function activityTarget(Project $project): array
    {
        ['targets' => $targets, 'rolled' => $rolled] = $this->activityFacts($project);
        $today = DailyFormSchedule::now()->toDateString();
        $total = $targets->count();

        // Targets without a day (or finished without a time) count from the start.
        $baseTarget = $targets->whereNull('target_on')->count();
        $baseActual = $targets->where('done', true)->whereNull('done_on')->count();

        $perDay = [];
        $bump = function (string $day, string $key) use (&$perDay): void {
            $perDay[$day] ??= ['target' => 0, 'actual' => 0, 'rolled' => 0];
            $perDay[$day][$key]++;
        };
        $targets->whereNotNull('target_on')->each(fn (array $target) => $bump($target['target_on'], 'target'));
        $targets->whereNotNull('done_on')->each(fn (array $target) => $bump($target['done_on'], 'actual'));
        $rolled->each(fn (array $change) => $bump($change['day'], 'rolled'));

        $done = $targets->where('done', true)->count();
        $plannedToDate = $baseTarget + collect($perDay)->filter(fn ($cell, string $day) => $day <= $today)->sum('target');
        $progress = Project::activityPercent($done, $total);
        $targetToDate = Project::activityPercent($plannedToDate, $total);

        $summary = [
            'total' => $total,
            'done' => $done,
            'progress' => $progress,
            'planned_to_date' => $plannedToDate,
            'target_to_date' => $targetToDate,
            'gap' => $progress - $targetToDate,
            'rolled' => $rolled->count(),
        ];

        if ($perDay === []) {
            return ['today' => $today, 'summary' => $summary, 'weeks' => []];
        }

        $first = min(array_keys($perDay));
        $last = max(array_keys($perDay));
        // A running project keeps "today" on the table even on a day without targets.
        if ($project->status === ProjectStatus::Active && $today > $last) {
            $last = $today;
        }

        $weeks = [];
        $cumTarget = $baseTarget;
        $cumActual = $baseActual;

        for ($day = Carbon::parse($first); $day->toDateString() <= $last; $day->addDay()) {
            $date = $day->toDateString();
            $cell = $perDay[$date] ?? null;

            // Off days appear only when something happened on them.
            if ($cell === null && ! DailyFormSchedule::isWorkDay($day)) {
                continue;
            }

            $cell ??= ['target' => 0, 'actual' => 0, 'rolled' => 0];
            $past = $date <= $today;
            $cumTarget += $cell['target'];
            $cumActual += $cell['actual'];

            $weekStart = $day->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
            $weeks[$weekStart] ??= [
                'start' => $weekStart,
                'end' => $day->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(),
                'target' => 0,
                'actual' => $weekStart <= $today ? 0 : null,
                'rolled' => 0,
                'cum_target' => 0,
                'cum_actual' => null,
                'days' => [],
            ];

            $row = [
                'date' => $date,
                'target' => $cell['target'],
                'actual' => $past ? $cell['actual'] : null,
                'rolled' => $cell['rolled'],
                'cum_target' => Project::activityPercent($cumTarget, $total),
                'cum_actual' => $past ? Project::activityPercent($cumActual, $total) : null,
            ];

            $week = &$weeks[$weekStart];
            $week['days'][] = $row;
            $week['target'] += $row['target'];
            $week['rolled'] += $row['rolled'];
            $week['cum_target'] = $row['cum_target'];
            if ($past) {
                $week['actual'] += $row['actual'];
                $week['cum_actual'] = $row['cum_actual'];
            }
            unset($week);
        }

        return ['today' => $today, 'summary' => $summary, 'weeks' => array_values($weeks)];
    }

    /**
     * D6/D7 — the one reading of a project's daily targets behind both
     * Target-vs-Actual views: a target's day is the day it was first
     * planned (`planned_date`, which never rolls), it counts as actual on
     * the day it was finished (WIB), and it "rolled" on every day it moved
     * away from unfinished.
     *
     * @return array{targets: Collection<int, array{staff_id: int, staff_name: ?string, target_on: ?string, done: bool, done_on: ?string}>, rolled: Collection<int, array{staff_id: int, day: string}>}
     */
    private function activityFacts(Project $project, bool $withStaff = false): array
    {
        $tasks = Task::query()
            ->where('project_id', $project->id)
            ->when($withStaff, fn ($query) => $query->with('assignee:id,name'))
            ->get(['id', 'assignee_id', 'planned_date', 'due_date', 'status', 'completed_at']);
        $assigneeOf = $tasks->pluck('assignee_id', 'id');

        $targets = $tasks->map(function (Task $task) use ($withStaff) {
            $done = $task->status === TaskStatus::Done;

            return [
                'staff_id' => (int) $task->assignee_id,
                'staff_name' => $withStaff ? $task->assignee?->name : null,
                'target_on' => ($task->planned_date ?? $task->due_date)?->toDateString(),
                'done' => $done,
                'done_on' => $done && $task->completed_at
                    ? $task->completed_at->copy()->timezone(DailyFormSchedule::TIMEZONE)->toDateString()
                    : null,
            ];
        });

        $rolled = TaskScheduleChange::query()
            ->whereIn('task_id', $tasks->pluck('id'))
            ->where('kind', TaskScheduleChange::ROLLOVER)
            ->get(['task_id', 'from_date'])
            ->map(fn (TaskScheduleChange $change) => [
                'staff_id' => (int) ($assigneeOf[$change->task_id] ?? 0),
                'day' => $change->from_date->toDateString(),
            ]);

        return ['targets' => $targets, 'rolled' => $rolled];
    }
}
