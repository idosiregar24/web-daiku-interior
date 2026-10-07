<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Services\WebPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Sprint 18 Sub 04 — the device half of delivery (DeliverNotificationJob is
 * the open-tab half). A job of its own so a push service hiccup retries the
 * push only, never re-broadcasts. A retried push reuses the same tag, so the
 * device shows one entry, not two.
 */
class PushNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public bool $deleteWhenMissingModels = true;

    /** @param  bool  $reminder  Sub 06 re-push of an unopened P1 ("Pengingat: …"). */
    public function __construct(public Notification $notification, public bool $reminder = false)
    {
        $this->onQueue('notifications');
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(WebPushService $service): void
    {
        $service->send($this->notification, $this->reminder);
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
