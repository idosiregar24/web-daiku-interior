<?php

namespace App\Services;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

class TaskService
{
    /**
     * PRD §7.1 "Task – Create/Edit" — PM only, no ownership scoping (same
     * as Project/Milestone). `is_locked` stays at its migration default
     * (true) — Field Staff can never touch title/description/due_date
     * regardless, per CLAUDE.md golden rule #6.
     */
    public function __construct(private NotificationService $notificationService) {}

    public function create(Project $project, array $data, User $actor): Task
    {
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

        // PRD §4.9 "Task baru di-assign → Tukang yang bersangkutan".
        if ($task->assignee) {
            $this->notificationService->notify(
                $task->assignee,
                'task_assigned',
                'Task Baru',
                "Anda mendapat task \"{$task->title}\" di proyek \"{$project->name}\", deadline {$task->due_date->translatedFormat('d F Y')}.",
                ['task_id' => $task->id, 'project_id' => $project->id],
            );
        }

        return $task;
    }

    /**
     * PRD §4.5 — only `status`/`kendala`/`note` change here, ever (task
     * immutability). `OVER` is deliberately not an allowed input value —
     * PRD: "Status OVER otomatis diset oleh sistem jika task belum DONE
     * melewati due_date", a scheduled job's job, not a manual choice (see
     * UpdateTaskStatusRequest and .claude/plan/README.md for why that job
     * isn't built yet). An already-OVER task can still be moved on to
     * DONE by its assignee — going OVER doesn't lock it, it's just a
     * missed-deadline marker.
     */
    public function updateStatus(Task $task, array $data, User $actor): Task
    {
        $task->update([
            'status' => $data['status'],
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
     * PRD §4.9 "Task overdue → PM proyek terkait": one notification per
     * project per run, listing that night's newly-overdue tasks.
     */
    public function markOverdueTasks(): int
    {
        $newlyOverdue = Task::query()
            ->overdue()
            ->where('status', '!=', TaskStatus::Over->value)
            ->with(['project.pm', 'assignee:id,name'])
            ->get();

        if ($newlyOverdue->isEmpty()) {
            return 0;
        }

        Task::whereKey($newlyOverdue->modelKeys())->update(['status' => TaskStatus::Over->value]);

        $newlyOverdue->groupBy('project_id')->each(function ($tasks) {
            $project = $tasks->first()->project;
            $titles = $tasks->take(3)->map(fn (Task $task) => "\"{$task->title}\" ({$task->assignee?->name})")->implode(', ');
            $more = $tasks->count() > 3 ? ' dan '.($tasks->count() - 3).' lainnya' : '';

            $this->notificationService->notifyMany(
                [$project->pm],
                'task_overdue',
                'Task Melewati Deadline',
                "{$tasks->count()} task di proyek \"{$project->name}\" melewati deadline: {$titles}{$more}.",
                ['project_id' => $project->id],
            );
        });

        return $newlyOverdue->count();
    }
}
