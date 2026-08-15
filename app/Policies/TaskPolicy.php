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
        return $user->hasRole('PM');
    }

    /** Status/kendala/note — PM (any task) or the task's own assignee. */
    public function updateStatus(User $user, Task $task): bool
    {
        if ($user->hasRole('PM')) {
            return true;
        }

        return $user->hasRole('FIELD_STAFF') && $task->assignee_id === $user->id;
    }
}
