<?php

use App\Jobs\ApplyDueSalaryChangesJob;
use App\Jobs\DailyFormReminderJob;
use App\Jobs\DailyPenaltyJob;
use App\Jobs\DesignDelayJob;
use App\Jobs\LeadFollowUpReminderJob;
use App\Jobs\MaterialRequestReminderJob;
use App\Jobs\MilestoneOverdueJob;
use App\Jobs\OpenKpiPeriodJob;
use App\Jobs\PruneNotificationsJob;
use App\Jobs\TaskOverdueJob;
use App\Jobs\TerminInvoiceReminderJob;
use App\Jobs\TerminOverdueJob;
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

// PRD §4.4 milestone OVERDUE (CSV Sprint 6) — same midnight run as tasks.
Schedule::job(new MilestoneOverdueJob)
    ->dailyAt('00:00')
    ->timezone('Asia/Jakarta');

// PRD §4.2 "Sistem hitung delay_hari otomatis setiap hari".
Schedule::job(new DesignDelayJob)
    ->dailyAt('00:00')
    ->timezone('Asia/Jakarta');

// PRD §4.4/§4.7 "TerminReminderJob: notif H-3 sebelum jadwal termin ke
// Finance" — checked once each morning.
Schedule::job(new TerminReminderJob)
    ->dailyAt('08:00')
    ->timezone('Asia/Jakarta');

// Sprint 12 #20 — Marketing is told when a scheme termin is due for invoicing.
Schedule::job(new TerminInvoiceReminderJob)
    ->dailyAt('07:30')
    ->timezone('Asia/Jakarta');

// PRD §4.9 "Termin overdue → Finance, CEO".
Schedule::job(new TerminOverdueJob)
    ->dailyAt('08:00')
    ->timezone('Asia/Jakarta');

// PRD §4.9 "Lead follow-up jatuh tempo → Marketing yang bertugas".
Schedule::job(new LeadFollowUpReminderJob)
    ->dailyAt('08:00')
    ->timezone('Asia/Jakarta');

// PRD §4.9 "Form daily task belum diisi → Tukang". CSV Sprint 5 says
// "jam 20:00" in the title but "30 menit sebelum penalti" in its note;
// the penalty runs 21:00, so 20:30 honors the more specific note. Same
// Senin–Sabtu working days as DailyPenaltyJob.
Schedule::job(new DailyFormReminderJob)
    ->days([1, 2, 3, 4, 5, 6])
    ->at('20:30')
    ->timezone('Asia/Jakarta');

// Sprint 11 decision #13 — material requests not reviewed within 1 working
// day remind Logistics (and a PM sitting on a Tukang request); the CEO gets
// a summary. Senin–Sabtu like the other working-day jobs; idempotent per day.
Schedule::job(new MaterialRequestReminderJob)
    ->days([1, 2, 3, 4, 5, 6])
    ->at('09:00')
    ->timezone('Asia/Jakarta');

// PRD §4.9 "Riwayat notifikasi tersimpan 90 hari".
Schedule::job(new PruneNotificationsJob)
    ->dailyAt('02:00')
    ->timezone('Asia/Jakarta');

// PRD §3.3 "mysqldump cron harian", §9.5 backup terenkripsi di lokasi
// terpisah, §11.4 "setiap tengah malam, retensi 30 hari" — see
// app/Console/Commands/DatabaseBackup.php and config/backup.php. Runs in the
// background so a long dump never delays the other midnight jobs; the
// overlap lock expires after 12h in case a run dies without releasing it
// (the default 24h would swallow the next night's backup).
Schedule::command('db:backup')
    ->dailyAt('00:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(12 * 60)
    ->runInBackground();

// SDM (Sprint 10, decision #3) — a salary change the CEO approved with a
// future effective date updates the base salary on that date. Idempotent:
// applied rows are stamped `applied_at` and never touched again.
Schedule::job(new ApplyDueSalaryChangesJob)
    ->dailyAt('00:15')
    ->timezone('Asia/Jakarta');

// SDM (Sprint 10, §3.3) — opens the month's KPI period on the 1st so HR
// can compute and fill it. Idempotent (an existing period is left alone).
Schedule::job(new OpenKpiPeriodJob)
    ->monthlyOn(1, '00:10')
    ->timezone('Asia/Jakarta');
