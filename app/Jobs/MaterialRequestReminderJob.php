<?php

namespace App\Jobs;

use App\Services\MaterialRequestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sprint 11 decision #13 — "Pengajuan yang belum ditinjau 1 hari kerja →
 * pengingat ke Logistik; CEO menerima ringkasan pengajuan tertunda".
 * Dispatched each working-day morning (routes/console.php). See
 * MaterialRequestService::sendReminders() — idempotent per day.
 */
class MaterialRequestReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(MaterialRequestService $service): void
    {
        $service->sendReminders();
    }
}
