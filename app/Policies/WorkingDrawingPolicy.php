<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkingDrawing;

/**
 * Sprint 24 D2 — Gambar Kerja. Routes already limit the roles; this
 * narrows a plain architect to the drawings they are PIC or assistant of
 * (same rule as DesignPolicy) and an Asisten PM to its own projects.
 * SUPERADMIN bypasses via AppServiceProvider's Gate::before.
 */
class WorkingDrawingPolicy
{
    public function view(User $user, WorkingDrawing $drawing): bool
    {
        if ($user->hasAnyRole(['CEO', 'PM', 'QA', 'KEPALA_DESAIN'])) {
            return true;
        }

        if ($user->hasRole('ASISTEN_PM')) {
            return (bool) $drawing->project?->isManagedBy($user);
        }

        return $user->hasRole('DESIGNER') && $drawing->hasMember($user);
    }

    /** The links and "Gambar Kerja Selesai": its team, or any Kepala Desain. */
    public function work(User $user, WorkingDrawing $drawing): bool
    {
        if ($user->hasRole('KEPALA_DESAIN')) {
            return true;
        }

        return $user->hasRole('DESIGNER') && $drawing->hasMember($user);
    }
}
