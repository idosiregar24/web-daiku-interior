<?php

use App\Jobs\DailyPenaltyJob;
use App\Jobs\TaskOverdueJob;
use App\Jobs\TerminReminderJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// PRD §6.5 — Senin–Sabtu jam 21:00 WIB, bukan `weekdaysOnly()` (itu
// Senin–Jumat) — PRD eksplisit menyebut Sabtu termasuk hari kerja.
// Day-of-week ints follow cron convention (0=Minggu…6=Sabtu), so 1-6 is
// Senin–Sabtu.
Schedule::job(new DailyPenaltyJob)
    ->days([1, 2, 3, 4, 5, 6])
    ->at('21:00')
    ->timezone('Asia/Jakarta');

// PRD §4.5 "Task overdue detection ... via scheduled job tengah malam".
Schedule::job(new TaskOverdueJob)
    ->dailyAt('00:00')
    ->timezone('Asia/Jakarta');

// PRD §4.4/§4.7 "TerminReminderJob: notif H-3 sebelum jadwal termin ke
// Finance" — checked once each morning.
Schedule::job(new TerminReminderJob)
    ->dailyAt('08:00')
    ->timezone('Asia/Jakarta');
