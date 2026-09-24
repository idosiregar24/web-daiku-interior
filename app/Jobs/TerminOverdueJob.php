<?php

namespace App\Jobs;

use App\Services\TerminService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** PRD §4.9 "Termin overdue → Finance, CEO" — dispatched daily (routes/console.php). See TerminService::markOverdue() for the idempotency note. */
class TerminOverdueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(TerminService $service): void
    {
        $service->markOverdue();
    }
}
