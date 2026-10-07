<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Penalty;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
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
    ) {}

    public function create(Project $project, array $data, User $actor): Task
    {
        $this->ensureProjectOpen($project);

        $task = Task::create([
            'project_id' => $project->id,
            'milestone_id' => $data['milestone_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'assignee_id' => $data['assignee_id'],
            'created_by' => $actor->id,
            'due_date' => $data['due_date'],
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
    public function updateStatus(Task $task, array $data, User $actor): Task
    {
        $task->update([
            'status' => $data['status'],
            'pre_overdue_status' => null,
            'kendala' => $data['kendala'] ?? $task->kendala,
            'note' => $data['note'] ?? $task->note,
            'completed_at' => $data['status'] === TaskStatus::Done->value ? now() : $task->completed_at,
        ]);

        return $task->fresh();
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
        $newlyOverdue = Task::query()
            ->overdue()
            ->where('status', '!=', TaskStatus::Over->value)
            ->whereHas('project', fn ($query) => $query->active())
            ->with(['project.pm', 'assignee:id,name'])
            ->get();

        if ($newlyOverdue->isEmpty()) {
            return 0;
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

            $this->notificationService->notifyMany(
                [$project->pm],
                NotificationType::TaskOverdue,
                'Task Melewati Deadline',
                "{$tasks->count()} task di proyek \"{$project->name}\" melewati deadline: {$titles}{$more}.",
                ['project_id' => $project->id],
            );
        });

        return $newlyOverdue->count();
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
