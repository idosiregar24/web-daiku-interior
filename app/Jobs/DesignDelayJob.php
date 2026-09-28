<?php

namespace App\Jobs;

use App\Services\DesignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** PRD §4.2 "Sistem hitung delay_hari otomatis setiap hari" — see DesignService::recalculateDelays(). */
class DesignDelayJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(DesignService $service): void
    {
        $service->recalculateDelays();
    }
}
