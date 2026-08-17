<?php

namespace App\Jobs;

use App\Enums\TerminStatus;
use App\Models\Termin;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * PRD §4.4/§4.7 "TerminReminderJob: notif H-3 sebelum jadwal termin ke
 * Finance" — dispatched daily (routes/console.php). Idempotent per day:
 * notifying is cheap and re-running the same day just re-sends the same
 * reminder rather than duplicating a persisted state change, so no extra
 * guard is needed beyond "still SCHEDULED".
 */
class TerminReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(NotificationService $notificationService): void
    {
        $targetDate = now('Asia/Jakarta')->addDays(3)->toDateString();

        $dueTermins = Termin::query()
            ->with('project:id,name')
            ->where('status', TerminStatus::Scheduled->value)
            ->whereDate('scheduled_date', $targetDate)
            ->get();

        if ($dueTermins->isEmpty()) {
            return;
        }

        $financeUsers = User::role('FINANCE')->get();

        foreach ($dueTermins as $termin) {
            $notificationService->notifyMany(
                $financeUsers,
                'termin_reminder',
                'Termin Jatuh Tempo H-3',
                "Termin #{$termin->termin_number} proyek \"{$termin->project->name}\" dijadwalkan {$termin->scheduled_date->translatedFormat('d F Y')} (3 hari lagi).",
                ['termin_id' => $termin->id, 'project_id' => $termin->project_id],
            );
        }
    }
}
