<?php

namespace App\Jobs;

use App\Services\PenaltyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * PRD §6.5 — dispatched Mon–Sat 21:00 WIB (routes/console.php).
 * Idempotent via PenaltyService::runDailyCheck() (backend-standards.md
 * §5) — safe to re-run for the same day without doubling penalties.
 */
class DailyPenaltyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(PenaltyService $service): void
    {
        $service->runDailyCheck();
    }
}
