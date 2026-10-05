<?php

namespace App\Jobs;

use App\Services\TerminService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Sprint 12 #20 — "Terbitkan invoice termin N" to Marketing, daily (routes/console.php). Idempotent — see TerminService::remindInvoices(). */
class TerminInvoiceReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(TerminService $service): void
    {
        $service->remindInvoices();
    }
}
