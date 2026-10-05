<?php

namespace App\Policies;

use App\Models\Design;
use App\Models\User;

/**
 * Sprint 12 decision #15 — "arsitek melihat desain yang ia anggotai,
 * Kepala Desain semua". The route already limits who reaches a design at
 * all (`design.*` role lists); this narrows a plain architect (DESIGNER
 * without KEPALA_DESAIN) to the designs they are PIC or assistant of.
 * SUPERADMIN bypasses via AppServiceProvider's Gate::before.
 */
class DesignPolicy
{
    public function view(User $user, Design $design): bool
    {
        if (! $user->hasRole('DESIGNER') || $user->hasRole('KEPALA_DESAIN')) {
            return true;
        }

        return $design->hasMember($user);
    }

    /** The brief (links, notes, …) — its team, or any Kepala Desain. */
    public function update(User $user, Design $design): bool
    {
        if ($user->hasRole('KEPALA_DESAIN')) {
            return true;
        }

        return $user->hasRole('DESIGNER') && $design->hasMember($user);
    }

    /** D6 — the Arsitek ↔ Estimator thread: any Estimator, a Kepala Desain, the design's own architects. */
    public function discuss(User $user, Design $design): bool
    {
        if ($user->hasAnyRole(['ESTIMATOR', 'KEPALA_DESAIN'])) {
            return true;
        }

        return $user->hasRole('DESIGNER') && $design->hasMember($user);
    }
}
