<?php

namespace App\Policies;

use App\Models\QaForm;
use App\Models\User;

/**
 * Sprint 24 D14 — Pengawas, QA and QC are one person: a timeline's QA
 * check belongs to the project's Pengawas (QA/QC). A project without one
 * (opened before Sprint 23) stays open to every user with role QA.
 * SUPERADMIN bypasses via AppServiceProvider's Gate::before.
 */
class QaFormPolicy
{
    public function review(User $user, QaForm $qaForm): bool
    {
        return (bool) $qaForm->project?->isQaReviewableBy($user);
    }
}
