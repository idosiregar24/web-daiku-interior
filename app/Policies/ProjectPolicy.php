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
        if ($user->hasAnyRole(['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'ASISTEN_PM', 'QA', 'FINANCE', 'LOGISTICS'])) {
            return true;
        }

        return $user->hasRole('FIELD_STAFF')
            && $project->tasks()->where('assignee_id', $user->id)->exists();
    }

    /**
     * Sprint 9 decision #1: CEO edits any project, a PM only the projects
     * they manage (PRD §9.2 "proyek milik PM") — PRD §7.1's bare PM "CRUD"
     * on the Project row doesn't make one PM the editor of another's
     * project. Whether the project is still editable at all (COMPLETED/
     * CANCELLED are read-only) is a business rule, checked in
     * ProjectService::update() so the user gets a message, not a 403.
     */
    public function update(User $user, Project $project): bool
    {
        if ($user->hasRole('CEO')) {
            return true;
        }

        return $user->hasRole('PM') && (int) $project->pm_id === (int) $user->id;
    }

    /**
     * Sprint 11 §5.6 "Rencana material dari katalog": Estimator and
     * Logistics on any project, a PM only on the projects they manage.
     */
    public function planMaterials(User $user, Project $project): bool
    {
        if ($user->hasAnyRole(['ESTIMATOR', 'LOGISTICS'])) {
            return true;
        }

        return $this->ownsAsPm($user, $project);
    }

    /**
     * Sprint 11 §5.6 — recording purchases, usage, waste and hand-overs on
     * a project's material lines: Logistics on any project, a PM only on
     * their own ("proyek miliknya" = `pm_id`, not just the PM role).
     * Issuing from and returning to the warehouse are Logistics-only and
     * gated by route middleware on top of this.
     */
    public function manageMaterials(User $user, Project $project): bool
    {
        return $user->hasRole('LOGISTICS') || $this->ownsAsPm($user, $project);
    }

    private function ownsAsPm(User $user, Project $project): bool
    {
        return $user->hasRole('PM') && (int) $project->pm_id === (int) $user->id;
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
        // ASISTEN_PM reads what the PM reads (Sprint 12 Sub 1).
        if ($user->hasAnyRole(['CEO', 'PM', 'ASISTEN_PM', 'SUPERADMIN'])) {
            return 'all';
        }

        return $user->hasRole('FIELD_STAFF') ? 'own' : 'none';
    }
}
