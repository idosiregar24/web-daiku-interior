<?php

namespace App\Jobs;

use App\Services\WebPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sprint 18 Sub 06 (K3) — every 15 minutes in working hours: an unopened
 * "klien menunggu" (P1) notification rings its recipient's devices once
 * more. Idempotent per row (`repushed_at`) — see
 * WebPushService::repushUnopenedClientWaiting().
 */
class RepushClientWaitingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(WebPushService $service): void
    {
        $service->repushUnopenedClientWaiting();
    }
}
