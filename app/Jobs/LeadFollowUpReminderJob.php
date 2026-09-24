<?php

namespace App\Jobs;

use App\Services\LeadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** PRD §4.9 "Lead follow-up jatuh tempo → Marketing yang bertugas" — dispatched each morning (routes/console.php). See LeadService::sendFollowUpReminders(). */
class LeadFollowUpReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(LeadService $service): void
    {
        $service->sendFollowUpReminders();
    }
}
