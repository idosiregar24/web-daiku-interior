<?php

namespace App\Services;

use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single write path for PRD §4.9 in-app notifications — every trigger
 * goes through here so rows are shaped the same and every one is pushed
 * to the recipient's Echo private channel (NotificationCreated).
 */
class NotificationService
{
    /** PRD §4.9 "Riwayat notifikasi tersimpan 90 hari" — see pruneOld(). */
    public const RETENTION_DAYS = 90;

    public function notify(User $user, string $type, string $title, string $message, array $metadata = []): Notification
    {
        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'metadata' => $metadata,
        ]);

        $this->broadcast($notification);

        return $notification;
    }

    /**
     * De-duplicated by user id — the same person can be reached twice
     * through one trigger's recipient list (e.g. "PM proyek + CEO" when
     * the CEO is also that project's PM), and should get one row, not two.
     *
     * @param  Collection<int, User>|array<int, User|null>  $users
     */
    public function notifyMany(iterable $users, string $type, string $title, string $message, array $metadata = []): void
    {
        collect($users)
            ->filter()
            ->unique('id')
            ->each(fn (User $user) => $this->notify($user, $type, $title, $message, $metadata));
    }

    /**
     * Every active holder of any of `$roles` — PRD §4.9 addresses most
     * recipients by division ("Finance", "Tim QA", "CEO"), not by person.
     *
     * @param  array<int, string>  $roles
     */
    public function notifyRoles(array $roles, string $type, string $title, string $message, array $metadata = []): void
    {
        $this->notifyMany(
            User::role($roles)->where('is_active', true)->get(),
            $type,
            $title,
            $message,
            $metadata,
        );
    }

    /**
     * Idempotency guard for scheduled reminder jobs (backend-standards.md
     * §5): has `$user` already been sent a `$type` notification about
     * this `$metadataKey` = `$id` today? Lets a re-run of the same day's
     * job skip instead of double-notifying.
     */
    public function alreadySentToday(User $user, string $type, string $metadataKey, int $id): bool
    {
        return Notification::where('user_id', $user->id)
            ->where('type', $type)
            ->where("metadata->{$metadataKey}", $id)
            ->where('created_at', '>=', now()->startOfDay())
            ->exists();
    }

    /** Idempotent — safe to re-run any number of times per day. */
    public function pruneOld(): int
    {
        return Notification::where('created_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();
    }

    /**
     * Real-time is best-effort on top of the persisted row, which stays
     * the source of truth (the bell also refreshes on every Inertia
     * visit — HandleInertiaRequests). So: only broadcast once the
     * caller's transaction has committed (a rolled-back QA review must
     * not have pinged the PM already), and never let a down Soketi
     * server turn an already-committed action into a 500 — `rescue()`
     * reports the failure and moves on.
     */
    private function broadcast(Notification $notification): void
    {
        DB::afterCommit(fn () => rescue(fn () => broadcast(new NotificationCreated($notification))));
    }
}
