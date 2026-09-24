<?php

namespace App\Jobs;

use App\Services\MilestoneService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** CSV Sprint 6 "Milestone status auto-update: OVERDUE jika lewat targetDate (scheduler)" — see MilestoneService::markOverdueMilestones(). */
class MilestoneOverdueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(MilestoneService $service): void
    {
        $service->markOverdueMilestones();
    }
}
