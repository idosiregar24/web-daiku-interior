<?php

namespace App\Policies;

use App\Models\DailyActivity;
use App\Models\User;

/**
 * Sprint 29 Sub 13 (I1) — "Aktivitas Harian": every account except a Tukang
 * writes its own entries; an entry is changed or removed only by its writer
 * and only on its own day. Nobody edits someone else's entry — HR, the CEO
 * and PMs only read them ("Periksa Aktivitas").
 */
class DailyActivityPolicy
{
    public function create(User $user): bool
    {
        return ! $user->hasRole('FIELD_STAFF');
    }

    public function update(User $user, DailyActivity $activity): bool
    {
        return $this->create($user) && (int) $activity->user_id === (int) $user->id && $activity->isEditableToday();
    }

    public function delete(User $user, DailyActivity $activity): bool
    {
        return $this->update($user, $activity);
    }
}
