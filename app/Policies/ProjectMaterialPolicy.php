<?php

namespace App\Policies;

use App\Enums\MaterialRequestStatus;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\User;

/**
 * Sprint 11 Sub 4 — who may raise and decide out-of-catalog material
 * requests (§5.6 + Sprint 12 decision #31). SUPERADMIN bypasses via
 * AppServiceProvider's Gate::before. Route middleware does the role
 * check; this adds the "on this project" part.
 *
 * Call as `$user->can('request', [ProjectMaterial::class, $project])`.
 */
class ProjectMaterialPolicy
{
    /**
     * Estimator on any project, a PM on the projects they manage, a Tukang
     * only on a project where they have a task. Logistics doesn't request
     * — it decides.
     */
    public function request(User $user, Project $project): bool
    {
        // Sprint 29 Sub 07 — the Admin Proyek also asks Logistik for out-of-catalog goods.
        if ($user->hasAnyRole(['ESTIMATOR', 'ADMIN_PROYEK'])) {
            return true;
        }

        // The project's PM, or its Asisten PM (Sprint 12 #22).
        if ($project->isManagedBy($user)) {
            return true;
        }

        return $user->hasRole('FIELD_STAFF')
            && $project->tasks()->where('assignee_id', $user->id)->exists();
    }

    /** A Tukang's request is approved first by the PM of that project — or its Asisten PM (Sprint 12 #22, #31). */
    public function pmDecide(User $user, ProjectMaterial $line): bool
    {
        return (bool) $line->project?->isManagedBy($user);
    }

    /** Logistics decides every request that reached it (decision #13 "satu pintu"). */
    public function review(User $user, ProjectMaterial $line): bool
    {
        return $user->hasRole('LOGISTICS');
    }

    /**
     * Sprint 29 Sub 11 (E5) — a line of the Rencana Bahan is its planners'
     * draft (the project's Pengawas, the Admin Proyek) until "Ajukan ke
     * Logistik"; from DIAJUKAN on only Logistik changes or removes it.
     */
    public function revisePlan(User $user, ProjectMaterial $line): bool
    {
        if ($line->request_channel !== ProjectMaterial::CHANNEL_RENCANA) {
            return false;
        }

        if ($user->hasRole('LOGISTICS')) {
            return true;
        }

        $project = $line->project;

        return $line->request_status === MaterialRequestStatus::Direncanakan
            && in_array($project->status, [ProjectStatus::Active, ProjectStatus::OnHold], true)
            && ($project->isSupervisedBy($user) || $project->isAdministeredBy($user));
    }
}
