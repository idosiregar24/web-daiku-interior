<?php

namespace App\Jobs;

use App\Services\WeeklyReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Sprint 24 D10 — Friday afternoon: the Pengawas of a producing project without this week's report. Idempotent per day. */
class WeeklyReportReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(WeeklyReportService $service): void
    {
        $service->sendReminders();
    }
}
