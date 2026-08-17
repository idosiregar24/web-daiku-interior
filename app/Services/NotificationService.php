<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Thin wrapper so every caller writes notifications the same shape — see
 * the `notifications` migration's docblock for what this deliberately
 * isn't (no real-time broadcast yet).
 */
class NotificationService
{
    public function notify(User $user, string $type, string $title, string $message, array $metadata = []): Notification
    {
        return Notification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'metadata' => $metadata,
        ]);
    }

    /** @param  Collection<int, User>|array<int, User>  $users */
    public function notifyMany(iterable $users, string $type, string $title, string $message, array $metadata = []): void
    {
        foreach ($users as $user) {
            $this->notify($user, $type, $title, $message, $metadata);
        }
    }
}
