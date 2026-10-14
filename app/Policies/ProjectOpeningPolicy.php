<?php

namespace App\Policies;

use App\Models\ProjectOpening;
use App\Models\User;

/**
 * Sprint 23 K13 — "Buka Proyek" belongs to the PM who approved that RAB
 * Proyek (ProjectOpening::canBeOpenedBy(): any PM when an Asisten PM
 * approved it or the designated PM is no longer an active PM). The route
 * adds `role:PM`; the CEO no longer opens. SUPERADMIN passes through
 * AppServiceProvider's Gate::before.
 */
class ProjectOpeningPolicy
{
    public function open(User $user, ProjectOpening $opening): bool
    {
        return $opening->canBeOpenedBy($user);
    }
}
