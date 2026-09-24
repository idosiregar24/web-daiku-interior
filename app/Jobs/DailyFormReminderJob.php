<?php

namespace App\Jobs;

use App\Services\PenaltyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** PRD §4.9 "Form daily task belum diisi → Tukang" — dispatched Mon–Sat 30 minutes before DailyPenaltyJob (routes/console.php). */
class DailyFormReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(PenaltyService $service): void
    {
        $service->sendDailyFormReminders();
    }
}
