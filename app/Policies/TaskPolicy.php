<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

/**
 * PRD §7.1 "Task – Create/Edit" (CEO=R, PM=CRUD) vs "Task – Update Status"
 * (CEO=R, PM=U, STF=U† — own task only). Route middleware alone can't
 * express STF's ownership restriction, hence this Policy — see
 * .claude/rules/security-standards.md §2 and CLAUDE.md golden rule #6.
 * SUPERADMIN bypasses via the `Gate::before` in AppServiceProvider, not
 * a check here.
 */
class TaskPolicy
{
    /** Full edit (title/description/due_date/priority/milestone/assignee/rate) — PM only, no ownership scoping (matches Project/Milestone's non-scoped PM CRUD). */
    public function update(User $user, Task $task): bool
    {
        return $user->hasRole('PM') || $this->assists($user, $task) || $this->supervises($user, $task);
    }

    /** Sprint 24 D9 — "Geser" a target inside its timeline: whoever plans it. */
    public function reschedule(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    /**
     * PRD §7.1 "Task – Create/Edit" PM "D" — same unscoped PM rule as
     * update(). Whether this particular task may still go (no history,
     * not DONE) is TaskService::delete()'s business rule, not an RBAC one.
     */
    public function delete(User $user, Task $task): bool
    {
        return $user->hasRole('PM') || $this->assists($user, $task) || $this->supervises($user, $task);
    }

    /** Status/kendala/note — PM (any task) or the task's own assignee. */
    public function updateStatus(User $user, Task $task): bool
    {
        if ($user->hasRole('PM') || $this->assists($user, $task) || $this->supervises($user, $task)) {
            return true;
        }

        return $user->hasRole('FIELD_STAFF') && (int) $task->assignee_id === (int) $user->id;
    }

    /** Sprint 24 D6 — the project's Pengawas (QA/QC) plans and checks its daily targets. */
    private function supervises(User $user, Task $task): bool
    {
        return (bool) $task->project?->isSupervisedBy($user);
    }

    /** Sprint 12 #22 — the Asisten PM of the task's project does what the PM does there. */
    private function assists(User $user, Task $task): bool
    {
        return $user->hasRole('ASISTEN_PM') && (bool) $task->project?->isManagedBy($user);
    }
}
