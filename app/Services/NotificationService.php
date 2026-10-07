<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Jobs\DeliverNotificationJob;
use App\Jobs\PushNotificationJob;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * Single write path for PRD §4.9 in-app notifications — every trigger
 * goes through here so rows are shaped the same and every one is pushed
 * to the recipient's Echo private channel (DeliverNotificationJob →
 * NotificationCreated).
 */
class NotificationService
{
    /** PRD §4.9 "Riwayat notifikasi tersimpan 90 hari" — see pruneOld(). */
    public const RETENTION_DAYS = 90;

    public function notify(User $user, NotificationType $type, string $title, string $message, array $metadata = []): Notification
    {
        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => $type->value,
            'title' => $title,
            'message' => $message,
            'metadata' => $metadata,
        ]);
        $this->deliver($notification, $user);

        return $notification;
    }

    /**
     * De-duplicated by user id — the same person can be reached twice
     * through one trigger's recipient list (e.g. "PM proyek + CEO" when
     * the CEO is also that project's PM), and should get one row, not two.
     *
     * @param  Collection<int, User>|array<int, User|null>  $users
     */
    public function notifyMany(iterable $users, NotificationType $type, string $title, string $message, array $metadata = []): void
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
    public function notifyRoles(array $roles, NotificationType $type, string $title, string $message, array $metadata = []): void
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
    public function alreadySentToday(User $user, NotificationType $type, string $metadataKey, int $id): bool
    {
        return Notification::where('user_id', $user->id)
            ->where('type', $type->value)
            ->where("metadata->{$metadataKey}", $id)
            ->where('created_at', '>=', now()->startOfDay())
            ->exists();
    }

    /**
     * Sprint 18 — `read_at` records *when*: the base for re-pushing an
     * unopened P1 and for measuring how fast people respond. A row that
     * is already read keeps its first timestamp.
     */
    public function markAsRead(Notification $notification): void
    {
        if (! $notification->is_read) {
            $notification->update(['is_read' => true, 'read_at' => now()]);
        }
    }

    public function markAllAsRead(User $user): void
    {
        Notification::where('user_id', $user->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);
    }

    /** Idempotent — safe to re-run any number of times per day. */
    public function pruneOld(): int
    {
        return Notification::where('created_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();
    }

    /**
     * Real-time is best-effort on top of the persisted row, which stays
     * the source of truth (the bell also refreshes on every Inertia
     * visit — HandleInertiaRequests). So: only hand it to the queue once
     * the caller's transaction has committed (a rolled-back QA review
     * must not have pinged the PM already), deliver it from a worker
     * (DeliverNotificationJob — a slow or down WebSocket server never
     * holds up the request), and never let even the dispatch itself turn
     * an already-committed action into a 500: `rescue()` reports it and
     * moves on (with QUEUE_CONNECTION=sync the job runs right here).
     * Devices (Web Push, Sprint 18 Sub 04) get their own job so either half
     * retries alone; P4 rows and users without a device skip it entirely.
     *
     * `Bus::dispatch()`, not `DeliverNotificationJob::dispatch()`: the
     * latter returns a PendingDispatch that only dispatches when it is
     * destructed — i.e. after `rescue()` has already returned, outside it.
     */
    private function deliver(Notification $notification, User $user): void
    {
        DB::afterCommit(function () use ($notification, $user) {
            // A notification usually means something just entered one of
            // the recipient's queues: drop their cached "Perlu Tindakan"
            // counts so the live reload of `navBadges` shows it at once.
            ActionInboxService::forget($user);

            rescue(fn () => Bus::dispatch(new DeliverNotificationJob($notification)));
            rescue(function () use ($notification, $user) {
                if (WebPushService::shouldPush($notification, $user)) {
                    Bus::dispatch(new PushNotificationJob($notification));
                }
            });
        });
    }
}
