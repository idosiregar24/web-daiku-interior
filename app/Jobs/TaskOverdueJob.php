<?php

namespace App\Jobs;

use App\Services\TaskService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * PRD §4.5 — dispatched daily at midnight (routes/console.php). See
 * TaskService::markOverdueTasks() for the idempotency note.
 */
class TaskOverdueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(TaskService $service): void
    {
        $service->markOverdueTasks();
    }
}
