<?php

namespace App\Jobs;

use App\Services\SalaryChangeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * SDM (Sprint 10, §3.2) — applies CEO-approved salary changes whose
 * effective date has arrived to `employees.base_salary`. Scheduled daily
 * at 00:15 Asia/Jakarta (routes/console.php); idempotent, so a re-run on
 * the same day applies nothing twice.
 */
class ApplyDueSalaryChangesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(SalaryChangeService $service): void
    {
        $service->applyDue();
    }
}
