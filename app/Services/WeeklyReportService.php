<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\ProjectPhase;
use App\Enums\ProjectStatus;
use App\Enums\WeeklyReportStatus;
use App\Models\Project;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Support\DailyFormSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 24 D10 — "Pengecekan mingguan (Jumat)": during production the
 * Pengawas reports each week (notes + photo/document links); the PM
 * approves — optionally with this week's Jasa Pengawas payment, which
 * goes straight to Finance — or rejects with a note (the Pengawas fixes
 * and re-sends the same report). A Friday reminder chases a missing one.
 */
class WeeklyReportService
{
    public function __construct(
        private NotificationService $notificationService,
        private AuditLogService $auditLogService,
        private SupervisorPaymentService $supervisorPaymentService,
    ) {}

    /**
     * Sprint 29 Sub 02 — the report may be sent any day of its week; from
     * the report day (config daiku.execution.weekly_report_day, Friday) to
     * the week's Sunday it is due. `$daysBefore` = how early a reminder
     * starts (the Perlu Tindakan queue: 1 = from Thursday).
     */
    public static function isDue(int $daysBefore = 0, ?Carbon $date = null): bool
    {
        $reportDay = (int) config('daiku.execution.weekly_report_day', 5);

        return ($date ?? DailyFormSchedule::now())->dayOfWeekIso >= $reportDay - $daysBefore;
    }

    /**
     * The production week a date falls in: its Monday and its number
     * (week 1 = the week production started).
     *
     * @return array{week_start: string, week_number: int}
     */
    public function weekOf(Project $project, ?Carbon $date = null): array
    {
        $date = ($date ?? DailyFormSchedule::now())->copy();
        $monday = $date->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $startedAt = ($project->production_started_at ?? $project->start_date ?? $date)->copy()->timezone(DailyFormSchedule::TIMEZONE);
        $firstMonday = Carbon::parse($startedAt->toDateString())->startOfWeek(Carbon::MONDAY);

        return [
            'week_start' => $monday->toDateString(),
            'week_number' => max(1, (int) floor($firstMonday->diffInDays($monday) / 7) + 1),
        ];
    }

    /**
     * The project's Pengawas sends this week's report (PRODUKSI or
     * PEMASANGAN). A rejected report of the same week is corrected in place.
     *
     * @param  array{notes: string, links?: ?array<int, string>}  $data
     */
    public function submit(Project $project, array $data, User $actor): WeeklyReport
    {
        return DB::transaction(function () use ($project, $data, $actor) {
            $project = Project::lockForUpdate()->findOrFail($project->id);

            if ($project->status !== ProjectStatus::Active || ! $project->currentPhase()->isProducing()) {
                throw ValidationException::withMessages(['notes' => 'Laporan mingguan diisi selama produksi / pemasangan berjalan.']);
            }

            $week = $this->weekOf($project);
            $links = collect($data['links'] ?? [])->map(fn ($url) => trim((string) $url))->filter()->values()->all();
            $report = WeeklyReport::where('project_id', $project->id)->whereDate('week_start', $week['week_start'])->first();

            if ($report && $report->status !== WeeklyReportStatus::Ditolak) {
                throw ValidationException::withMessages(['notes' => "Laporan minggu ke-{$week['week_number']} sudah dikirim."]);
            }

            $attributes = [
                'supervisor_id' => $actor->id,
                'notes' => trim($data['notes']),
                'links' => $links,
                'status' => WeeklyReportStatus::Diajukan->value,
                'submitted_at' => now(),
                'decided_by' => null,
                'decided_at' => null,
                'decision_note' => null,
            ];

            $report = $report
                ? tap($report)->update($attributes)
                : WeeklyReport::create([...$attributes, 'project_id' => $project->id, ...$week]);

            // Sprint 29 Sub 02 — "Pengecekan mingguan belum diisi" leaves the Pengawas's queue at once.
            DB::afterCommit(fn () => ActionInboxService::forget($actor));

            $this->auditLogService->record('weekly_report.submitted', $report, null, [
                'week_number' => $report->week_number,
                'notes' => $report->notes,
                'links' => $links,
            ], $actor);

            $this->notificationService->notifyMany(
                [$project->pm],
                NotificationType::WeeklyReportSubmitted,
                "Laporan Mingguan · Minggu ke-{$report->week_number}",
                "{$actor->name} mengirim laporan mingguan proyek \"{$project->name}\" — setujui atau tolak di tab Eksekusi.",
                ['project_id' => $project->id],
            );

            return $report;
        });
    }

    /** PM: approve (+ optional weekly payment → Finance) or reject (note required). */
    public function decide(WeeklyReport $report, bool $approve, ?float $paymentAmount, ?string $note, User $actor): WeeklyReport
    {
        return DB::transaction(function () use ($report, $approve, $paymentAmount, $note, $actor) {
            $report = WeeklyReport::lockForUpdate()->findOrFail($report->id);

            if ($report->status !== WeeklyReportStatus::Diajukan) {
                throw ValidationException::withMessages(['report' => 'Laporan ini sudah diputuskan.']);
            }

            if (! $approve && blank($note)) {
                throw ValidationException::withMessages(['note' => 'Alasan penolakan wajib diisi.']);
            }

            $report->update([
                'status' => ($approve ? WeeklyReportStatus::Disetujui : WeeklyReportStatus::Ditolak)->value,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => filled($note) ? trim($note) : null,
            ]);

            if ($approve && $paymentAmount !== null && $paymentAmount > 0) {
                $this->supervisorPaymentService->createWeekly($report, $paymentAmount, $actor);
            }

            $this->auditLogService->record($approve ? 'weekly_report.approved' : 'weekly_report.rejected', $report, ['status' => WeeklyReportStatus::Diajukan->value], [
                'status' => $report->status->value,
                'payment_amount' => $approve ? $paymentAmount : null,
                'note' => $report->decision_note,
            ], $actor);

            $this->notificationService->notifyMany(
                [$report->supervisor],
                NotificationType::WeeklyReportDecided,
                $approve ? "Laporan Minggu ke-{$report->week_number} Disetujui" : "Laporan Minggu ke-{$report->week_number} Ditolak",
                $approve
                    ? "{$actor->name} menyetujui laporan mingguan proyek \"{$report->project->name}\"."
                    : "{$actor->name} menolak laporan mingguan proyek \"{$report->project->name}\": {$report->decision_note} — perbaiki lalu kirim ulang.",
                ['project_id' => $report->project_id],
            );

            return $report;
        });
    }

    /**
     * The Friday reminder (WeeklyReportReminderJob): every producing
     * project whose Pengawas hasn't sent this week's report. Idempotent per
     * day. Returns how many were reminded.
     */
    public function sendReminders(?Carbon $date = null): int
    {
        $date ??= DailyFormSchedule::now();
        $sent = 0;

        Project::query()
            ->active()
            ->whereIn('phase', [ProjectPhase::Produksi->value, ProjectPhase::Pemasangan->value])
            ->whereHas('supervisors')
            ->with('supervisors')
            ->each(function (Project $project) use ($date, &$sent) {
                $week = $this->weekOf($project, $date);
                $sentThisWeek = WeeklyReport::where('project_id', $project->id)
                    ->whereDate('week_start', $week['week_start'])
                    ->where('status', '!=', WeeklyReportStatus::Ditolak->value)
                    ->exists();

                if ($sentThisWeek) {
                    return;
                }

                // Sprint 29 Sub 06 — every Pengawas of the project (one report per week, any of them).
                foreach ($project->supervisors as $supervisor) {
                    if (! $supervisor->is_active || $this->notificationService->alreadySentToday($supervisor, NotificationType::WeeklyReportReminder, 'project_id', $project->id)) {
                        continue;
                    }

                    $this->notificationService->notify(
                        $supervisor,
                        NotificationType::WeeklyReportReminder,
                        "Laporan Mingguan Belum Diisi · Minggu ke-{$week['week_number']}",
                        "Isi laporan pengecekan mingguan proyek \"{$project->name}\" hari ini.",
                        ['project_id' => $project->id],
                    );
                    $sent++;
                }
            });

        return $sent;
    }
}
