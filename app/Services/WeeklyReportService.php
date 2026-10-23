<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\ProjectPhase;
use App\Enums\ProjectStatus;
use App\Enums\SupervisorPaymentStatus;
use App\Enums\WeeklyReportStatus;
use App\Models\Project;
use App\Models\SupervisorPayment;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Support\DailyFormSchedule;
use App\Support\Quantity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 24 D10 — "Pengecekan mingguan (Jumat)": during production the
 * Pengawas reports each week (notes + photo/document links); the PM
 * approves — optionally with this week's Jasa Pengawas payment, which
 * goes straight to Finance — or rejects with a note (the Pengawas fixes
 * and re-sends the same report). A Friday reminder chases a missing one.
 *
 * Sprint 29 Sub 09 (C1/B5) — a project with wages per RAB item: the report
 * is the pengecekan — the % added per item (work_item_progress) — and
 * sending it asks "cair kali ini" as the week's payment (DIAJUKAN); the PM
 * decides both at once (may lower the amount; rejecting keeps the %). The
 * typed weekly amount stays for older projects only.
 */
class WeeklyReportService
{
    public function __construct(
        private NotificationService $notificationService,
        private AuditLogService $auditLogService,
        private SupervisorPaymentService $supervisorPaymentService,
        private WorkItemService $workItemService,
        private ProductionService $productionService,
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
     * Sprint 36 — the report / pengecekan (the wage request) is sent only on
     * the send days (config daiku.execution.weekly_report_send_days: Friday,
     * Saturday); before them it is filled as a draft.
     */
    public static function sendWindowOpen(?Carbon $date = null): bool
    {
        return in_array(($date ?? DailyFormSchedule::now())->dayOfWeekIso, self::sendDays(), true);
    }

    /** @return list<int> ISO days (1 = Senin … 7 = Minggu) */
    public static function sendDays(): array
    {
        return array_map('intval', (array) config('daiku.execution.weekly_report_send_days', [5, 6]));
    }

    /** "Jumat atau Sabtu" */
    public static function sendDaysLabel(): string
    {
        $names = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

        return collect(self::sendDays())->map(fn (int $day) => $names[$day] ?? '')->filter()->join(', ', ' atau ');
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
     * Whether a report / pengecekan can be sent now — the same rule submit()
     * enforces. Sprint 36: on the send days only, once a week (again after
     * the PM rejected it).
     */
    public function canSubmit(Project $project): bool
    {
        return self::sendWindowOpen() && $this->sentThisWeek($project) === null;
    }

    /**
     * This week's report that still counts (waiting for the PM or approved) —
     * a rejected one leaves the week open for a corrected one.
     */
    public function sentThisWeek(Project $project): ?WeeklyReport
    {
        $report = WeeklyReport::where('project_id', $project->id)->whereDate('week_start', $this->weekOf($project)['week_start'])->orderByDesc('sequence')->first();

        return $report && $report->status !== WeeklyReportStatus::Ditolak ? $report : null;
    }

    /**
     * The project's Pengawas sends this week's report (PRODUKSI or
     * PEMASANGAN). A rejected report of the same week is corrected in place.
     *
     * Sprint 31 — with wages per RAB item the report is a pengecekan, and a
     * week may hold several (File C: 20 and 25 Oct): each is a new row
     * (`sequence`), sent once the week's previous one was decided by the PM
     * — within a week one money request waits for the PM at a time.
     *
     * @param  array{notes: string, links?: ?array<int, string>, progress?: ?array<int, array{item_id: int|string, percent: numeric-string|int|float, note?: string|null}>}  $data
     */
    public function submit(Project $project, array $data, User $actor): WeeklyReport
    {
        return DB::transaction(function () use ($project, $data, $actor) {
            $project = Project::lockForUpdate()->findOrFail($project->id);

            if ($project->status !== ProjectStatus::Active || ! $project->currentPhase()->isProducing()) {
                throw ValidationException::withMessages(['notes' => 'Laporan mingguan diisi selama produksi / pemasangan berjalan.']);
            }

            if (! self::sendWindowOpen()) {
                throw ValidationException::withMessages(['notes' => 'Pengecekan dikirim hari '.self::sendDaysLabel().' — sebelum itu isian disimpan sebagai draf.']);
            }

            $week = $this->weekOf($project);
            $links = collect($data['links'] ?? [])->map(fn ($url) => trim((string) $url))->filter()->values()->all();
            $usesWorkItems = $this->workItemService->hasWorkItems($project);
            $report = WeeklyReport::where('project_id', $project->id)->whereDate('week_start', $week['week_start'])->orderByDesc('sequence')->first();
            $sequence = 1;

            // Sprint 36 — once a week; a report the PM rejected is sent again (corrected).
            if ($report && $report->status !== WeeklyReportStatus::Ditolak) {
                throw ValidationException::withMessages(['notes' => $report->status === WeeklyReportStatus::Diajukan
                    ? "Pengecekan {$report->label()} sudah dikirim dan menunggu keputusan PM."
                    : "Pengecekan minggu ke-{$week['week_number']} sudah dikirim — berikutnya minggu depan."]);
            }

            if ($usesWorkItems) {
                // A pengecekan sent again after a rejection is a new row (the rejected one stays as history).
                $sequence = ($report?->sequence ?? 0) + 1;
                $report = null;
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
                : WeeklyReport::create([...$attributes, 'project_id' => $project->id, ...$week, 'sequence' => $sequence]);

            // Sprint 29 Sub 02 — "Pengecekan mingguan belum diisi" leaves the Pengawas's queue at once.
            DB::afterCommit(fn () => ActionInboxService::forget($actor));

            $this->auditLogService->record('weekly_report.submitted', $report, null, [
                'week_number' => $report->week_number,
                'sequence' => $report->sequence,
                'notes' => $report->notes,
                'links' => $links,
            ], $actor);

            // Sprint 29 Sub 09 — the % per item, then "cair kali ini" as this week's request.
            $payment = null;

            if ($usesWorkItems) {
                $this->workItemService->recordProgress($project, $data['progress'] ?? [], $report, $actor);
                $this->workItemService->clearDraft($project);
                $payment = $this->supervisorPaymentService->requestForReport($project, $report, $actor);
            }

            $this->notificationService->notifyMany(
                [$project->pm],
                NotificationType::WeeklyReportSubmitted,
                $payment ? "Pengajuan Upah Tukang · {$report->label()}" : "Laporan Mingguan · {$report->label()}",
                $payment
                    ? "{$actor->name} mengirim pengecekan proyek \"{$project->name}\" (progres ".Quantity::format($this->workItemService->weightedProgress($project) ?? 0).' %) dan mengajukan '.mb_strtolower($payment->kind->label()).' upah Rp '.number_format((float) $payment->amount, 0, ',', '.').' — setujui atau tolak di Item RAB › Lembar Progres.'
                    : ($usesWorkItems
                        ? "{$actor->name} mengirim pengecekan proyek \"{$project->name}\" — setujui atau tolak di Item RAB › Lembar Progres."
                        : "{$actor->name} mengirim laporan mingguan proyek \"{$project->name}\" — setujui atau tolak di tab Tahapan."),
                // Sprint 31 — a pengecekan per item is decided on the Lembar Progres (NotificationTarget).
                ['project_id' => $project->id, 'item_sheet' => $usesWorkItems],
            );

            // Sprint 29 Sub 10 (B7) — the pengecekan moves the progres berbobot: the 70 % moment may be now.
            if ($usesWorkItems) {
                $this->productionService->checkProgress($project);
            }

            return $report;
        });
    }

    /**
     * PM: approve or reject (note required). Sprint 29 Sub 09 — a report that
     * asked "cair kali ini" decides that payment too: approve with the amount
     * (may be lowered; 0 = not this week), reject = the request is rejected
     * as well (the % stay recorded — asked again with the next pengecekan).
     * An older project without work items: the PM may still type this week's
     * payment (straight to Finance).
     */
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

            $pending = SupervisorPayment::where('weekly_report_id', $report->id)->where('status', SupervisorPaymentStatus::Diajukan->value)->first();

            if ($pending) {
                $pays = $approve && ($paymentAmount === null || $paymentAmount > 0);
                $pending = $this->supervisorPaymentService->decide(
                    $pending,
                    $pays,
                    $pays ? ($paymentAmount ?? (float) $pending->amount) : null,
                    $pays ? $note : ($note ?: 'Tidak dicairkan minggu ini.'),
                    $actor,
                    fromReport: true,
                );
                $paymentAmount = $pays ? (float) $pending->amount : null;
            } elseif ($approve && $paymentAmount !== null && $paymentAmount > 0 && ! $this->workItemService->hasWorkItems($report->project)) {
                $this->supervisorPaymentService->createWeekly($report, $paymentAmount, $actor);
            } else {
                $paymentAmount = null;
            }

            $this->auditLogService->record($approve ? 'weekly_report.approved' : 'weekly_report.rejected', $report, ['status' => WeeklyReportStatus::Diajukan->value], [
                'status' => $report->status->value,
                'payment_amount' => $approve ? $paymentAmount : null,
                'note' => $report->decision_note,
            ], $actor);

            $this->notificationService->notifyMany(
                [$report->supervisor],
                NotificationType::WeeklyReportDecided,
                $approve ? "Laporan {$report->label()} Disetujui" : "Laporan {$report->label()} Ditolak",
                $approve
                    ? "{$actor->name} menyetujui laporan mingguan proyek \"{$report->project->name}\"".($paymentAmount ? ' — upah Rp '.number_format($paymentAmount, 0, ',', '.').' menunggu dibayar Finance.' : '.')
                    : "{$actor->name} menolak laporan mingguan proyek \"{$report->project->name}\": {$report->decision_note} — perbaiki lalu kirim ulang.",
                ['project_id' => $report->project_id, 'item_sheet' => $this->workItemService->hasWorkItems($report->project)],
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
