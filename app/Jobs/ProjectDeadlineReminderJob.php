<?php

namespace App\Jobs;

use App\Services\ProductionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Sprint 24 D13 "H-30%" — every morning, the PM of each ACTIVE project past 70 % of its duration. Idempotent per day. */
class ProjectDeadlineReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(ProductionService $service): void
    {
        $service->sendDeadlineReminders();
    }
}
