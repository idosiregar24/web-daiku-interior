<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * PRD §7.1 "Project (overview)": every business role reads, but Field
 * Staff is R* — "hanya data milik user". security-standards.md §2 makes
 * this Policy mandatory: ProjectController::index() already scopes the
 * list, but without this a tukang could open any project by editing the
 * URL. SUPERADMIN bypasses via AppServiceProvider's Gate::before.
 */
class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        if ($user->hasAnyRole(['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'QA', 'FINANCE', 'LOGISTICS'])) {
            return true;
        }

        return $user->hasRole('FIELD_STAFF')
            && $project->tasks()->where('assignee_id', $user->id)->exists();
    }

    /**
     * Which task rows a viewer of this project may see — PRD §7.1 gives
     * task read to CEO/PM (all) and Field Staff (own only, U†). QA
     * explicitly never sees task detail (PRD §4.6), and the remaining
     * roles have no task row in the matrix at all.
     *
     * @return 'all'|'own'|'none'
     */
    public function taskVisibility(User $user): string
    {
        if ($user->hasAnyRole(['CEO', 'PM', 'SUPERADMIN'])) {
            return 'all';
        }

        return $user->hasRole('FIELD_STAFF') ? 'own' : 'none';
    }
}
