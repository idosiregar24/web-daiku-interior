<?php

namespace App\Jobs;

use App\Events\NotificationCreated;
use App\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Sprint 18 Sub 01 — pushes an already-stored notification out to its
 * recipient. The row written by NotificationService::notify() is the
 * source of truth; this job only *delivers* it, so a retry can never
 * create a second row (backend-standards.md §5, idempotent jobs).
 *
 * Queued on its own `notifications` queue (workers list it first) instead
 * of broadcasting inside the request: a WebSocket server that is down or
 * slow used to add a ~2 s cURL timeout per recipient to the action that
 * notified (laravel.log, 2026-10-07). Now it only costs this job a retry.
 */
class DeliverNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** A notification pruned (or its user removed) before delivery is simply dropped. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Notification $notification)
    {
        $this->onQueue('notifications');
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(): void
    {
        broadcast(new NotificationCreated($this->notification));
    }

    /** Out of retries: the row stays in the bell, only the live push is lost. */
    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
