<?php

namespace App\Jobs;

use App\Services\TechnicalMeetingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Sprint 34 — the H-1 and 2-hours-before reminders of a scheduled TM. Idempotent (stamped per meeting). */
class TechnicalMeetingReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(TechnicalMeetingService $service): void
    {
        $service->sendReminders();
    }
}
