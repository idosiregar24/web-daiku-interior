<?php

namespace App\Jobs;

use App\Services\KpiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * SDM (Sprint 10, §3.3) "Job terjadwal tanggal 1 menyiapkan periode bulan
 * berjalan" — opens the current month's KPI period. Idempotent:
 * KpiService::openPeriod() returns the existing period on a re-run.
 */
class OpenKpiPeriodJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(KpiService $service): void
    {
        $service->openPeriod(now()->format('Y-m'));
    }
}
