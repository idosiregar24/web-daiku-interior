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
    public function create(Project $project, array $data, User $actor): Task
    {
        return Task::create([
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
     * see routes/console.php). Idempotent: re-running only touches tasks
     * still not-DONE and past due, so it never "un-overdues" a task moved
     * on to DONE afterward.
     */
    public function markOverdueTasks(): int
    {
        return Task::query()->overdue()->update(['status' => TaskStatus::Over->value]);
    }
}
