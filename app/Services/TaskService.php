<?php

namespace App\Services;

use App\Enums\MilestoneStatus;
use App\Enums\NotificationType;
use App\Enums\ProjectPhase;
use App\Enums\SiteFindingStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\DailyTaskForm;
use App\Models\Milestone;
use App\Models\Penalty;
use App\Models\Project;
use App\Models\SiteFinding;
use App\Models\Task;
use App\Models\TaskScheduleChange;
use App\Models\User;
use App\Support\DailyFormSchedule;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskService
{
    /** Sprint 9 decision #2 — a task with a trail is corrected, never deleted. */
    public const HISTORY_MESSAGE = 'Task sudah punya riwayat (form harian/lembur/penalti/pembayaran) — ubah task-nya, jangan dihapus.';

    /** What a DONE-but-unpaid task still lets the PM change — the rest describes work already accepted. */
    private const DONE_EDITABLE = ['title', 'description', 'rate_per_task'];

    /**
     * PRD §7.1 "Task – Create/Edit" — PM only, no ownership scoping (same
     * as Project/Milestone). `is_locked` stays at its migration default
     * (true) — Field Staff can never touch title/description/due_date
     * regardless, per CLAUDE.md golden rule #6.
     */
    public function __construct(
        private NotificationService $notificationService,
        private AuditLogService $auditLogService,
        private StaffPaymentService $staffPaymentService,
        private ProductionService $productionService,
    ) {}

    /**
     * Sprint 24 D6 — a task is a "Daily Target": planned by the Pengawas
     * (or PM / Asisten PM) once the TM is done (phase PERSIAPAN on), always
     * in a timeline and on a day inside that timeline. `planned_date` keeps
     * the day it was planned for (target vs actual). A repair task from a
     * site finding (`$repair`, D12) may fall after its timeline.
     */
    public function create(Project $project, array $data, User $actor, bool $repair = false): Task
    {
        $this->ensureProjectOpen($project);

        if (! $repair && ! $project->currentPhase()->allowsTargets()) {
            throw ValidationException::withMessages([
                'title' => 'Daily target baru bisa dibuat setelah Technical Meeting selesai (tahap '.$project->currentPhase()->label().').',
            ]);
        }

        $milestone = filled($data['milestone_id'] ?? null) ? Milestone::find($data['milestone_id']) : null;

        if (! $repair) {
            $this->ensureWithinTimeline($milestone, Carbon::parse($data['due_date'])->toDateString());
        }

        $task = Task::create([
            'project_id' => $project->id,
            'milestone_id' => $milestone?->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'assignee_id' => $data['assignee_id'],
            'created_by' => $actor->id,
            'due_date' => $data['due_date'],
            'planned_date' => $data['due_date'],
            'priority' => $data['priority'] ?? TaskPriority::Medium->value,
            'rate_per_task' => $data['rate_per_task'] ?? null,
        ]);

        $this->notifyAssignee($task, $project);

        return $task;
    }

    /**
     * "Edit Task" (Sprint 9 decision #2) — PM only (TaskPolicy::update()).
     * Field Staff never reach this: their side of a task is
     * updateStatus() (CLAUDE.md golden rule #6).
     *
     * - Closed (COMPLETED/CANCELLED) projects: refused.
     * - DONE and wage already paid: fully locked. DONE, unpaid: only
     *   title/description/rate_per_task — the rate is what Finance will pay.
     * - An OVER task whose deadline moves to today or later gets back the
     *   status it had before markOverdueTasks() flagged it.
     * - A new assignee is notified exactly like on creation; rate and
     *   assignee changes are audited (they decide who gets paid what).
     *
     * The row is locked first — StaffPaymentService::pay() locks it too,
     * so an edit and a wage payment can't interleave.
     *
     * @param  array{title: string, description?: ?string, due_date: string, priority: string, milestone_id?: int|string|null, assignee_id: int|string, rate_per_task?: numeric-string|int|float|null}  $data
     */
    public function update(Task $task, array $data, User $actor): Task
    {
        return DB::transaction(function () use ($task, $data, $actor) {
            /** @var Task $locked */
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $locked->load(['project', 'assignee:id,name', 'milestone:id,name']);

            $this->ensureProjectOpen($locked->project);

            $isDone = $locked->status === TaskStatus::Done;

            if ($isDone && $this->staffPaymentService->isTaskPaid($locked)) {
                throw ValidationException::withMessages([
                    'task' => 'Upah task ini sudah dibayar — task terkunci dan tidak bisa diubah lagi.',
                ]);
            }

            $before = $this->editableSnapshot($locked);
            $incoming = [
                'title' => $data['title'],
                'description' => filled($data['description'] ?? null) ? $data['description'] : null,
                'due_date' => Carbon::parse($data['due_date'])->toDateString(),
                'priority' => $data['priority'],
                'milestone_id' => filled($data['milestone_id'] ?? null) ? (int) $data['milestone_id'] : null,
                'assignee_id' => (int) $data['assignee_id'],
                'rate_per_task' => filled($data['rate_per_task'] ?? null) ? $this->money($data['rate_per_task']) : null,
            ];

            $changed = array_keys(array_filter(
                $incoming,
                fn ($value, string $field) => $value !== $before[$field],
                ARRAY_FILTER_USE_BOTH,
            ));

            $frozenChanges = $isDone ? array_values(array_diff($changed, self::DONE_EDITABLE)) : [];

            if ($frozenChanges !== []) {
                throw ValidationException::withMessages(array_fill_keys(
                    $frozenChanges,
                    'Task sudah DONE — hanya judul, deskripsi, dan rate yang masih bisa diubah.',
                ));
            }

            $attributes = array_intersect_key($incoming, array_flip($changed));

            // Sprint 24 D6 — the target day stays inside its timeline (a repair task excepted).
            if (array_intersect($changed, ['due_date', 'milestone_id']) !== [] && ! $locked->siteFinding()->exists()) {
                $this->ensureWithinTimeline(
                    $incoming['milestone_id'] ? Milestone::find($incoming['milestone_id']) : null,
                    $incoming['due_date'],
                    nullable: $before['milestone_id'] === null && $incoming['milestone_id'] === null,
                );
            }

            // A target nobody worked on yet is re-planned, not moved: planned_date follows.
            if (in_array('due_date', $changed, true) && $locked->status === TaskStatus::Pending && $locked->rollover_count === 0) {
                $attributes['planned_date'] = $incoming['due_date'];
            }

            // Sprint 9 decision #2: deadline pushed back → the OVER flag was
            // about the old deadline, so restore what the task was doing.
            if ($locked->status === TaskStatus::Over && $incoming['due_date'] >= now('Asia/Jakarta')->toDateString()) {
                $attributes['status'] = ($locked->pre_overdue_status ?? TaskStatus::Pending)->value;
                $attributes['pre_overdue_status'] = null;
            }

            if ($attributes === []) {
                return $locked;
            }

            $previousAssignee = $locked->assignee?->name;
            $previousMilestone = $locked->milestone?->name;
            $previousStatus = $locked->status->value;

            $locked->update($attributes);
            $locked->load(['assignee:id,name', 'milestone:id,name']);

            if (in_array('due_date', $changed, true)) {
                $this->logScheduleChange($locked, $before['due_date'], $incoming['due_date'], TaskScheduleChange::EDIT, $actor);
            }

            if (in_array('assignee_id', $changed, true)) {
                $this->notifyAssignee($locked, $locked->project);
            } elseif (! $isDone && array_intersect($changed, ['title', 'description', 'due_date']) !== []) {
                // Sprint 18 Sub 06 — the Tukang can't edit these (PRD §4.5),
                // so a PM change to their work or deadline must reach them.
                $this->notifyAssigneeOfChange($locked, $locked->project, in_array('due_date', $changed, true) ? $before['due_date'] : null);
            }

            if (array_intersect($changed, ['rate_per_task', 'assignee_id']) !== []) {
                $old = array_intersect_key($before, array_flip($changed));
                $new = array_intersect_key($incoming, array_flip($changed));

                if (isset($attributes['status'])) {
                    $old['status'] = $previousStatus;
                    $new['status'] = $attributes['status'];
                }

                $this->auditLogService->record(
                    'task.updated',
                    $locked,
                    $this->readableAuditValues($old, $previousAssignee, $previousMilestone),
                    $this->readableAuditValues($new, $locked->assignee?->name, $locked->milestone?->name),
                    $actor,
                );
            }

            return $locked;
        });
    }

    /**
     * "Hapus Task" (Sprint 9 decision #2) — only a task nobody has acted on
     * yet: not DONE, and no daily form, overtime request, penalty or wage
     * payment pointing at it. Anything with history is corrected through
     * update() instead, so those records never lose their task (daily
     * forms would cascade away, overtime would be orphaned). Audited with
     * a snapshot of what was removed.
     */
    public function delete(Task $task, User $actor): void
    {
        DB::transaction(function () use ($task, $actor) {
            /** @var Task $locked */
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            $locked->load(['project:id,name,status', 'assignee:id,name', 'milestone:id,name']);

            $this->ensureProjectOpen($locked->project);

            if ($locked->status === TaskStatus::Done) {
                throw ValidationException::withMessages([
                    'task' => 'Task yang sudah DONE tidak bisa dihapus — ubah task-nya, jangan dihapus.',
                ]);
            }

            if ($this->hasHistory($locked)) {
                throw ValidationException::withMessages(['task' => self::HISTORY_MESSAGE]);
            }

            $this->auditLogService->record('task.deleted', $locked, [
                'title' => $locked->title,
                'description' => $locked->description,
                'project' => $locked->project->name,
                'milestone' => $locked->milestone?->name,
                'assignee' => $locked->assignee?->name,
                'status' => $locked->status->value,
                'priority' => $locked->priority?->value,
                'due_date' => $locked->due_date?->toDateString(),
                'rate_per_task' => $locked->rate_per_task,
            ], null, $actor);

            $locked->delete();
        });
    }

    /** Daily form, overtime request, penalty or wage payment — see delete(). */
    public function hasHistory(Task $task): bool
    {
        return $task->dailyTaskForms()->exists()
            || $task->overtimeRequests()->exists()
            // PRD §5.1 penalties.reference_id is "task_id atau daily_form
            // tanggal" — whenever it holds a number, that number is a task id.
            || Penalty::where('reference_id', $task->id)->exists()
            || $this->staffPaymentService->isTaskPaid($task);
    }

    /**
     * PRD §4.5 — only `status`/`kendala`/`note` change here, ever (task
     * immutability). `OVER` is deliberately not an allowed input value —
     * PRD: "Status OVER otomatis diset oleh sistem jika task belum DONE
     * melewati due_date", a scheduled job's job (markOverdueTasks()), not a
     * manual choice (see UpdateTaskStatusRequest). An already-OVER task can still be moved on to
     * DONE by its assignee — going OVER doesn't lock it, it's just a
     * missed-deadline marker. Moving it on also drops the remembered
     * pre-OVER status, which only means something while the task is OVER.
     */
    public function updateStatus(Task $task, array $data, User $actor, bool $recordActivity = true): Task
    {
        $task->loadMissing('project');
        $isAssignee = (int) $task->assignee_id === (int) $actor->id;

        // Sprint 29 Sub 04 (F1) — the Tukang's "Selesai" asks the Pengawas to
        // check it; only the Pengawas / PM side turns a target DONE (approve()).
        if ($isAssignee && ! $this->checksTargets($actor) && $data['status'] === TaskStatus::Done->value && $task->status !== TaskStatus::Done) {
            $data['status'] = TaskStatus::Pengecekan->value;
        }

        // Sprint 24 D1 — the Tukang starts on the targets once production started (DP approved by the PM).
        if ($isAssignee && $task->project && ! $task->project->currentPhase()->isProducing()) {
            throw ValidationException::withMessages([
                'status' => 'Produksi proyek ini belum dimulai — tunggu Start Produksi dari PM.',
            ]);
        }

        $becomesDone = $data['status'] === TaskStatus::Done->value && $task->status !== TaskStatus::Done;

        $closedFindings = 0;

        DB::transaction(function () use ($task, $data, $actor, $isAssignee, $recordActivity, $becomesDone, &$closedFindings) {
            $task->update([
                'status' => $data['status'],
                'pre_overdue_status' => null,
                'kendala' => $data['kendala'] ?? $task->kendala,
                'note' => $data['note'] ?? $task->note,
                'completed_at' => $data['status'] === TaskStatus::Done->value ? ($becomesDone ? now() : ($task->completed_at ?? now())) : $task->completed_at,
            ]);

            // Sprint 24 D7 — the Tukang's own check-in IS the day's actual activity
            // (it replaces the separate daily form; DailyTaskFormService passes false).
            if ($isAssignee && $recordActivity) {
                $this->recordActivity($task, $data, $actor);
            }

            if ($becomesDone) {
                // D12 — a repair task closes its finding.
                $closedFindings = SiteFinding::where('task_id', $task->id)->open()->update([
                    'status' => SiteFindingStatus::Selesai->value,
                    'resolved_by' => $actor->id,
                    'resolved_at' => now(),
                ]);
            }
        });

        if ($becomesDone && $task->project) {
            $this->productionService->checkProgress($task->project);

            // Sprint 29 Sub 12 — the last finding closing may complete a project with QA per item.
            if ($closedFindings > 0) {
                SiteFindingService::afterClosed($task->project);
            }
        }

        return $task->fresh();
    }

    /**
     * Sprint 29 Sub 04 (F2) — "Setujui": a target the Tukang reported done
     * becomes DONE, completed when the Tukang marked it (their check-in).
     */
    public function approve(Task $task, User $actor): Task
    {
        if ($task->status !== TaskStatus::Pengecekan) {
            throw ValidationException::withMessages(['status' => "Target \"{$task->title}\" tidak sedang menunggu dicek."]);
        }

        $reportedAt = $task->dailyTaskForms()->where('status_update', TaskStatus::Pengecekan->value)->latest('submitted_at')->value('submitted_at');

        return DB::transaction(function () use ($task, $actor, $reportedAt) {
            $task = $this->updateStatus($task, ['status' => TaskStatus::Done->value], $actor, recordActivity: false);

            if ($reportedAt) {
                $task->update(['completed_at' => $reportedAt]);
            }

            $this->auditLogService->record('task.approved', $task, ['status' => TaskStatus::Pengecekan->value], ['status' => TaskStatus::Done->value], $actor);

            return $task;
        });
    }

    /**
     * Sprint 29 Sub 04 (F4) — "Setujui semua": every target of the given ids
     * that waits for a check, in one transaction.
     *
     * @param  list<int>  $taskIds
     */
    public function approveMany(Project $project, array $taskIds, User $actor): int
    {
        return DB::transaction(function () use ($project, $taskIds, $actor) {
            $tasks = Task::query()
                ->where('project_id', $project->id)
                ->whereIn('id', $taskIds)
                ->where('status', TaskStatus::Pengecekan->value)
                ->lockForUpdate()
                ->get();

            $tasks->each(fn (Task $task) => $this->approve($task, $actor));

            return $tasks->count();
        });
    }

    /**
     * Sprint 29 Sub 04 (F2) — "Kembalikan": not done yet → back to the
     * Tukang (Dikerjakan) with the reason, told by notification, audited.
     */
    public function returnToStaff(Task $task, string $note, User $actor): Task
    {
        if ($task->status !== TaskStatus::Pengecekan) {
            throw ValidationException::withMessages(['note' => "Target \"{$task->title}\" tidak sedang menunggu dicek."]);
        }

        return DB::transaction(function () use ($task, $note, $actor) {
            $task->update(['status' => TaskStatus::OnProgress->value, 'pre_overdue_status' => null]);
            $this->auditLogService->record('task.returned', $task, ['status' => TaskStatus::Pengecekan->value], [
                'status' => TaskStatus::OnProgress->value,
                'note' => trim($note),
            ], $actor);

            if ($task->assignee) {
                $this->notificationService->notify(
                    $task->assignee,
                    NotificationType::TaskUpdated,
                    'Target Dikembalikan',
                    "Target \"{$task->title}\" dikembalikan {$actor->name}: ".trim($note),
                    ['task_id' => $task->id, 'project_id' => $task->project_id],
                );
            }

            return $task->fresh();
        });
    }

    /**
     * Sprint 29 Sub 04 (F3) — the Pengawas ticks a target done for a Tukang
     * who doesn't use the phone: DONE at once, and today's check-in of the
     * Tukang is recorded (no penalty, Q30) — who ticked it is in the audit.
     */
    public function checkOff(Task $task, User $actor): Task
    {
        $task->loadMissing(['project', 'assignee']);

        if ($task->status === TaskStatus::Done) {
            throw ValidationException::withMessages(['status' => "Target \"{$task->title}\" sudah selesai."]);
        }

        if ($task->status === TaskStatus::Pengecekan) {
            return $this->approve($task, $actor);
        }

        if ($task->project && ! $task->project->currentPhase()->isProducing()) {
            throw ValidationException::withMessages(['status' => 'Produksi proyek ini belum dimulai — target belum bisa dicentang.']);
        }

        return DB::transaction(function () use ($task, $actor) {
            $before = $task->status->value;
            $task = $this->updateStatus($task, ['status' => TaskStatus::Done->value], $actor, recordActivity: false);

            if ($task->assignee) {
                $this->recordActivity($task, [
                    'status' => TaskStatus::Done->value,
                    'note' => "Dicentang selesai oleh {$actor->name}",
                ], $task->assignee);
            }

            $this->auditLogService->record('task.checked_by_supervisor', $task, ['status' => $before], ['status' => TaskStatus::Done->value], $actor);

            return $task;
        });
    }

    /**
     * Sprint 29 Sub 04 (K5) — one target for several tukang × the working
     * days of a range: create() per row, one transaction (a row outside
     * its timeline refuses the lot).
     *
     * @param  array<string, mixed>  $data  StoreTasksRequest's validated data
     * @return list<Task>
     */
    public function createMany(Project $project, array $data, User $actor): array
    {
        $days = collect(CarbonPeriod::create($data['date_from'], $data['date_to']))
            ->filter(fn ($day) => DailyFormSchedule::isWorkDay(Carbon::parse($day->toDateString(), DailyFormSchedule::TIMEZONE)))
            ->map(fn ($day) => $day->toDateString())
            ->values();

        if ($days->isEmpty()) {
            throw ValidationException::withMessages(['date_from' => 'Tidak ada hari kerja (Senin–Sabtu) di rentang ini.']);
        }

        $base = collect($data)->except(['assignee_ids', 'date_from', 'date_to'])->all();

        return DB::transaction(fn () => collect(array_values(array_unique($data['assignee_ids'])))
            ->flatMap(fn ($assigneeId) => $days->map(fn (string $day) => $this->create($project, [...$base, 'assignee_id' => $assigneeId, 'due_date' => $day], $actor)))
            ->all());
    }

    /** Whoever checks targets (Pengawas / PM / Asisten PM) — their own DONE is final. */
    private function checksTargets(User $actor): bool
    {
        return $actor->hasAnyRole(['PM', 'ASISTEN_PM', 'QA', 'SUPERADMIN', 'ADMIN_PROYEK']);
    }

    /**
     * Sprint 24 D7 — today's activity row of this task (one per task per
     * working day, the latest check-in wins). What the penalty run checks.
     */
    public function recordActivity(Task $task, array $data, User $actor): ?DailyTaskForm
    {
        $now = DailyFormSchedule::now();

        if (! DailyFormSchedule::isWorkDay($now)) {
            return null;
        }

        $attributes = [
            'staff_id' => $actor->id,
            'status_update' => $data['status'],
            'kendala' => $data['kendala'] ?? null,
            'notes' => $data['note'] ?? $data['notes'] ?? null,
            'submitted_at' => $now,
        ];
        $existing = DailyTaskForm::where('task_id', $task->id)->forDate($now->toDateString())->first();

        if ($existing) {
            $existing->update($attributes);

            return $existing;
        }

        return DailyTaskForm::create([...$attributes, 'task_id' => $task->id, 'work_date' => $now->toDateString()]);
    }

    /**
     * PRD §4.5 "Status OVER otomatis diset oleh sistem jika task belum
     * DONE melewati due_date" — dispatched at midnight (TaskOverdueJob,
     * see routes/console.php). Idempotent: only tasks not already OVER are
     * picked up, so a re-run neither re-flags nor re-notifies, and it
     * never "un-overdues" a task moved on to DONE afterward.
     *
     * Only ACTIVE projects: an ON_HOLD/COMPLETED/CANCELLED project's
     * deadlines are paused (Sprint 9 decision #1). Each task's current
     * status is kept in `pre_overdue_status` so update() can restore it.
     *
     * PRD §4.9 "Task overdue → PM proyek terkait": one notification per
     * project per run, listing that night's newly-overdue tasks.
     */
    public function markOverdueTasks(): int
    {
        $late = Task::query()
            ->overdue()
            ->where('status', '!=', TaskStatus::Over->value)
            // Sprint 24 D1 — targets of a project that isn't producing yet wait for Start Produksi.
            ->whereHas('project', fn ($query) => $query->active()->whereIn('phase', [ProjectPhase::Produksi->value, ProjectPhase::Pemasangan->value]))
            ->with(['project.pm', 'project.supervisors', 'assignee:id,name', 'milestone:id,start_date,target_date'])
            ->get();

        if ($late->isEmpty()) {
            return 0;
        }

        // Sprint 24 D8 — an unfinished target rolls to the next working day while
        // that day is still inside its timeline; only past the timeline is it OVER.
        $nextDay = DailyFormSchedule::nextWorkDay(DailyFormSchedule::now());
        [$rolling, $newlyOverdue] = $late->partition(
            fn (Task $task) => $task->milestone?->target_date !== null && $nextDay->lte($task->milestone->target_date),
        );

        $rolling->each(function (Task $task) use ($nextDay) {
            $from = $task->due_date?->toDateString();
            $rolled = Task::query()
                ->whereKey($task->id)
                ->where('status', $task->status->value)
                ->whereDate('due_date', $from)
                ->update(['due_date' => $nextDay->toDateString(), 'rollover_count' => $task->rollover_count + 1]);

            if ($rolled) {
                $this->logScheduleChange($task, $from, $nextDay->toDateString(), TaskScheduleChange::ROLLOVER, null);
            }
        });

        if ($newlyOverdue->isEmpty()) {
            return $rolling->count();
        }

        // One UPDATE per previous status; the status guard skips a task its
        // assignee moved on in the meantime instead of overwriting it.
        $newlyOverdue
            ->groupBy(fn (Task $task) => $task->status->value)
            ->each(fn ($tasks, string $status) => Task::query()
                ->whereKey($tasks->pluck('id')->all())
                ->where('status', $status)
                ->update(['status' => TaskStatus::Over->value, 'pre_overdue_status' => $status]));

        $newlyOverdue->groupBy('project_id')->each(function ($tasks) {
            $project = $tasks->first()->project;
            $titles = $tasks->take(3)->map(fn (Task $task) => "\"{$task->title}\" ({$task->assignee?->name})")->implode(', ');
            $more = $tasks->count() > 3 ? ' dan '.($tasks->count() - 3).' lainnya' : '';

            // Sprint 24 — the Pengawas plans the targets, so they hear it too.
            $this->notificationService->notifyMany(
                $project->supervisors()->get()->push($project->pm)->filter()->unique('id'),
                NotificationType::TaskOverdue,
                'Task Melewati Deadline',
                "{$tasks->count()} task di proyek \"{$project->name}\" melewati deadline: {$titles}{$more}.",
                ['project_id' => $project->id],
            );
        });

        return $rolling->count() + $newlyOverdue->count();
    }

    /**
     * Sprint 24 D9 — "Geser": the Pengawas / PM moves one unfinished target
     * to another day inside its timeline. Logged; the Tukang is told.
     */
    public function reschedule(Task $task, string $toDate, User $actor, ?string $note = null): Task
    {
        return DB::transaction(function () use ($task, $toDate, $actor, $note) {
            $locked = Task::query()->lockForUpdate()->with(['project', 'milestone', 'assignee'])->findOrFail($task->id);
            $this->ensureProjectOpen($locked->project);
            $toDate = Carbon::parse($toDate)->toDateString();
            $fromDate = $locked->due_date?->toDateString();

            if ($locked->status === TaskStatus::Done) {
                throw ValidationException::withMessages(['due_date' => 'Target yang sudah selesai tidak bisa digeser.']);
            }

            if ($toDate === $fromDate) {
                return $locked;
            }

            $this->ensureWorkDay($toDate, 'due_date');
            // Sprint 29 Sub 05 (F7, Q29) — any working day; the target follows the timeline of its new day.
            $milestone = $this->timelineOn($locked, $toDate);
            $attributes = ['due_date' => $toDate, 'milestone_id' => $milestone?->id];

            if ($locked->status === TaskStatus::Over && $toDate >= DailyFormSchedule::now()->toDateString()) {
                $attributes['status'] = ($locked->pre_overdue_status ?? TaskStatus::Pending)->value;
                $attributes['pre_overdue_status'] = null;
            }

            $moved = $this->timelineMoveNote($locked->milestone, $milestone);
            $locked->update($attributes);
            $locked->setRelation('milestone', $milestone);
            $this->logScheduleChange($locked, $fromDate, $toDate, TaskScheduleChange::GESER, $actor, collect([$note, $moved])->filter()->implode(' · '));
            $this->notifyAssigneeOfChange($locked, $locked->project, $fromDate);

            return $locked;
        });
    }

    /**
     * Sprint 24 D9 — "Tukar": every unfinished target of `$dateA` goes to
     * `$dateB` and back (optionally one Tukang's only). Sprint 29 Sub 05
     * (F7) — not bound to the timelines: each target follows the timeline
     * of its new day (Q29). Refused as a whole when one would land on a day
     * that isn't a working day (Sprint 28 K13) — only the landing day
     * counts: a target left on a Sunday may still be swapped off it.
     */
    public function swapDays(Project $project, string $dateA, string $dateB, ?int $assigneeId, User $actor): int
    {
        return DB::transaction(function () use ($project, $dateA, $dateB, $assigneeId, $actor) {
            $this->ensureProjectOpen($project);
            $dateA = Carbon::parse($dateA)->toDateString();
            $dateB = Carbon::parse($dateB)->toDateString();

            if ($dateA === $dateB) {
                throw ValidationException::withMessages(['to_date' => 'Pilih dua tanggal yang berbeda.']);
            }

            /** @var Collection<int, Task> $tasks */
            $tasks = Task::query()
                ->where('project_id', $project->id)
                ->where('status', '!=', TaskStatus::Done->value)
                ->where(fn ($query) => $query->whereDate('due_date', $dateA)->orWhereDate('due_date', $dateB))
                ->when($assigneeId, fn ($query) => $query->where('assignee_id', $assigneeId))
                ->with(['milestone', 'assignee'])
                ->lockForUpdate()
                ->get();

            if ($tasks->isEmpty()) {
                throw ValidationException::withMessages(['from_date' => 'Tidak ada target yang belum selesai di kedua tanggal itu.']);
            }

            foreach ($tasks as $task) {
                $target = $task->due_date->toDateString() === $dateA ? $dateB : $dateA;
                $day = Carbon::parse($target, DailyFormSchedule::TIMEZONE);

                if (! DailyFormSchedule::isWorkDay($day)) {
                    throw ValidationException::withMessages([
                        $target === $dateB ? 'to_date' : 'from_date' => "Target \"{$task->title}\" akan pindah ke hari {$day->locale('id')->translatedFormat('l')} — bukan hari kerja, tukar dibatalkan.",
                    ]);
                }
            }

            foreach ($tasks as $task) {
                $from = $task->due_date->toDateString();
                $target = $from === $dateA ? $dateB : $dateA;
                $milestone = $this->timelineOn($task, $target);
                $moved = $this->timelineMoveNote($task->milestone, $milestone);
                $task->update(['due_date' => $target, 'milestone_id' => $milestone?->id]);
                $this->logScheduleChange($task, $from, $target, TaskScheduleChange::TUKAR, $actor, $moved);
            }

            $tasks->groupBy('assignee_id')->each(function (Collection $own) use ($project, $dateA, $dateB) {
                $assignee = $own->first()->assignee;

                if ($assignee) {
                    $this->notificationService->notify(
                        $assignee,
                        NotificationType::TaskUpdated,
                        'Jadwal Target Ditukar',
                        "Target proyek \"{$project->name}\" tanggal ".Carbon::parse($dateA)->translatedFormat('d M').' dan '.Carbon::parse($dateB)->translatedFormat('d M').' ditukar — cek lagi tugas Anda.',
                        ['project_id' => $project->id],
                    );
                }
            });

            return $tasks->count();
        });
    }

    /**
     * Sprint 28 K13 — a target is moved ("Geser"/"Tukar") only onto a working
     * day (config daiku.daily_form.work_days via DailyFormSchedule): no
     * daily form, no report and no penalty run on the others.
     */
    /**
     * Sprint 29 Sub 05 (Q29) — the timeline a moved target belongs to on
     * `$date`: its own when the day is inside it, else the project's
     * (not yet QA-passed) timeline covering that day, else its own.
     */
    private function timelineOn(Task $task, string $date): ?Milestone
    {
        $own = $task->milestone;

        if ($own && $own->start_date?->toDateString() <= $date && $own->target_date?->toDateString() >= $date) {
            return $own;
        }

        return Milestone::query()
            ->where('project_id', $task->project_id)
            ->where('status', '!=', MilestoneStatus::Completed->value)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('target_date', '>=', $date)
            ->orderBy('order')
            ->orderBy('id')
            ->first() ?? $own;
    }

    /** "Pindah timeline: A → B" for the schedule history, null when it stayed. */
    private function timelineMoveNote(?Milestone $from, ?Milestone $to): ?string
    {
        return $from?->id !== $to?->id ? "Pindah timeline: {$from?->name} → {$to?->name}" : null;
    }

    private function ensureWorkDay(string $date, string $field): void
    {
        $day = Carbon::parse($date, DailyFormSchedule::TIMEZONE);

        if (! DailyFormSchedule::isWorkDay($day)) {
            throw ValidationException::withMessages([
                $field => 'Hari '.$day->locale('id')->translatedFormat('l').' bukan hari kerja — pilih Senin–Sabtu.',
            ]);
        }
    }

    /**
     * Sprint 24 D6/D9 — a target day lies inside its timeline (start → end).
     * `$nullable`: a pre-Sprint-24 task without timeline may keep having none.
     */
    private function ensureWithinTimeline(?Milestone $milestone, string $date, bool $nullable = false): void
    {
        if (! $milestone) {
            if ($nullable) {
                return;
            }

            throw ValidationException::withMessages(['milestone_id' => 'Daily target wajib berada di salah satu timeline.']);
        }

        $start = $milestone->start_date?->toDateString();
        $end = $milestone->target_date?->toDateString();

        if (($start && $date < $start) || ($end && $date > $end)) {
            $range = ($start ? Carbon::parse($start)->translatedFormat('d M Y') : '…').' – '.($end ? Carbon::parse($end)->translatedFormat('d M Y') : '…');

            throw ValidationException::withMessages([
                'due_date' => "Tanggal target harus di dalam timeline \"{$milestone->name}\" ({$range}).",
            ]);
        }
    }

    private function logScheduleChange(Task $task, ?string $from, string $to, string $kind, ?User $actor, ?string $note = null): void
    {
        TaskScheduleChange::create([
            'task_id' => $task->id,
            'from_date' => $from,
            'to_date' => $to,
            'kind' => $kind,
            'changed_by' => $actor?->id,
            'note' => filled($note) ? mb_substr(trim($note), 0, 255) : null,
        ]);
    }

    /** Sprint 9 decision #1 — a COMPLETED/CANCELLED project's task plan is final. */
    private function ensureProjectOpen(Project $project): void
    {
        if ($project->isClosed()) {
            throw ValidationException::withMessages([
                'project' => "Proyek \"{$project->name}\" sudah {$project->status->value} — task-nya tidak bisa ditambah, diubah, atau dihapus lagi.",
            ]);
        }
    }

    /** PRD §4.9 "Task baru di-assign → Tukang yang bersangkutan" — also on re-assignment. */
    private function notifyAssignee(Task $task, Project $project): void
    {
        if (! $task->assignee) {
            return;
        }

        $this->notificationService->notify(
            $task->assignee,
            NotificationType::TaskAssigned,
            'Task Baru',
            "Anda mendapat task \"{$task->title}\" di proyek \"{$project->name}\", deadline {$task->due_date->translatedFormat('d F Y')}.",
            ['task_id' => $task->id, 'project_id' => $project->id],
        );
    }

    private function notifyAssigneeOfChange(Task $task, Project $project, ?string $previousDueDate): void
    {
        if (! $task->assignee) {
            return;
        }

        $deadline = $task->due_date->translatedFormat('d F Y');
        $message = $previousDueDate
            ? "Deadline task \"{$task->title}\" di proyek \"{$project->name}\" diubah PM: ".Carbon::parse($previousDueDate)->translatedFormat('d F Y')." → {$deadline}."
            : "PM mengubah isi task \"{$task->title}\" di proyek \"{$project->name}\" (deadline {$deadline}). Cek lagi sebelum bekerja.";

        $this->notificationService->notify(
            $task->assignee,
            NotificationType::TaskUpdated,
            'Task Diubah',
            $message,
            ['task_id' => $task->id, 'project_id' => $project->id],
        );
    }

    /** The PM-editable fields, normalized so an unchanged field compares equal. */
    private function editableSnapshot(Task $task): array
    {
        return [
            'title' => $task->title,
            'description' => filled($task->description) ? $task->description : null,
            'due_date' => $task->due_date?->toDateString(),
            'priority' => $task->priority?->value,
            'milestone_id' => $task->milestone_id === null ? null : (int) $task->milestone_id,
            'assignee_id' => (int) $task->assignee_id,
            'rate_per_task' => $task->rate_per_task === null ? null : $this->money($task->rate_per_task),
        ];
    }

    /** Audit rows show names, not foreign keys, for the assignee/milestone. */
    private function readableAuditValues(array $values, ?string $assignee, ?string $milestone): array
    {
        if (array_key_exists('assignee_id', $values)) {
            unset($values['assignee_id']);
            $values['assignee'] = $assignee;
        }

        if (array_key_exists('milestone_id', $values)) {
            unset($values['milestone_id']);
            $values['milestone'] = $milestone;
        }

        return $values;
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
