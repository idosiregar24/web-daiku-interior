<?php

namespace App\Services;

use App\Enums\ProjectPhase;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\SiteFinding;
use App\Models\SupervisorPayment;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Support\DailyFormSchedule;
use Illuminate\Support\Carbon;

/**
 * Sprint 24 — everything the project's "Eksekusi" tab shows, built once:
 * the phase stepper, Gambar Kerja, TM + its checklist, Jasa Pengawas and
 * its payments, the DP conditions, weekly reports, actual-activity
 * progress and "Berangkat ke Lokasi", target vs actual, site findings and
 * the H-30% warning — plus what this viewer may do on it. Money (fee and
 * payments) only reaches CEO, the PMs, Finance and the project's Pengawas.
 */
class ProjectExecutionService
{
    public function __construct(
        private TechnicalMeetingService $technicalMeetingService,
        private SupervisorPaymentService $supervisorPaymentService,
        private WeeklyReportService $weeklyReportService,
        private ProductionService $productionService,
    ) {}

    /** Who gets the tab at all. */
    public static function canView(User $user, Project $project): bool
    {
        if ($user->hasAnyRole(['CEO', 'PM', 'QA', 'FINANCE', 'LOGISTICS', 'SUPERADMIN'])) {
            return true;
        }

        return $user->hasRole('ASISTEN_PM') && $project->isManagedBy($user);
    }

    /** @return array<string, mixed> */
    public function overview(Project $project, User $user, ?string $week = null): array
    {
        $phase = $project->currentPhase();
        $active = $project->status === ProjectStatus::Active;
        $seesMoney = $user->hasAnyRole(['CEO', 'PM', 'FINANCE', 'SUPERADMIN']) || $project->isSupervisedBy($user);
        $supervises = $user->can('supervise', $project);
        $approves = $user->can('approveExecution', $project);
        $runsMeeting = $user->can('runMeeting', $project);
        $managesTargets = $user->can('manageTargets', $project) && ! $project->isClosed();

        $drawing = $project->workingDrawing()->with(['pic:id,name', 'staff:id,name', 'completer:id,name'])->first();
        $meeting = $project->technicalMeeting()->with(['attendees.user:id,name', 'completer:id,name'])->first();
        $progress = $project->actualProgress();
        $producing = $phase->isProducing();
        $weekStart = $this->weekStart($week);

        return [
            'phase' => $phase->value,
            'phases' => array_map(fn (ProjectPhase $step) => [
                'value' => $step->value,
                'label' => $step->label(),
                'state' => $step === $phase ? 'current' : ($phase->rank() > $step->rank() ? 'done' : 'upcoming'),
            ], ProjectPhase::cases()),
            // Opened before Sprint 24: no Gambar Kerja / TM records, started straight at PRODUKSI.
            'isLegacy' => $drawing === null && $meeting === null && $phase === ProjectPhase::Produksi,
            'workingDrawing' => $drawing ? [
                'id' => $drawing->id,
                'status' => $drawing->status->value,
                'pic' => $drawing->pic?->name,
                'staff' => $drawing->staff->pluck('name')->all(),
                'deadline' => $drawing->deadline?->toDateString(),
                'drawing_urls' => $drawing->drawing_urls ?? [],
                'note' => $drawing->note,
                'completed_at' => $drawing->completed_at?->toIso8601String(),
                'completer' => $drawing->completer?->name,
                'canOpen' => $user->can('view', $drawing),
            ] : null,
            'meeting' => $meeting ? [
                'meeting_date' => $meeting->meeting_date?->toDateString(),
                'notes' => $meeting->notes,
                'attendees' => $meeting->attendees->map(fn ($row) => ['user_id' => $row->user_id, 'name' => $row->name, 'label' => $row->label])->values()->all(),
                'completed_at' => $meeting->completed_at?->toIso8601String(),
                'completer' => $meeting->completer?->name,
            ] : null,
            'meetingRequirements' => $phase === ProjectPhase::Tm ? $this->technicalMeetingService->requirements($project) : [],
            // The TM form's pickers: attendees (every active account but technical ones), and E1's
            // default Tanggal Selesai (the project's, else the last timeline's end).
            'attendeeOptions' => $active && $phase === ProjectPhase::Tm && $runsMeeting
                ? User::query()->where('is_active', true)->whereDoesntHave('roles', fn ($role) => $role->where('name', 'SUPERADMIN'))->orderBy('name')->get(['id', 'name'])
                : [],
            'defaultEndDate' => $project->end_date?->toDateString() ?? $project->milestones()->reorder()->max('target_date'),
            'fee' => $seesMoney ? $this->supervisorPaymentService->summary($project) : null,
            'dpRequirements' => $phase === ProjectPhase::Persiapan ? $this->supervisorPaymentService->dpRequirements($project) : [],
            'payments' => $seesMoney
                ? $project->supervisorPayments()
                    ->with(['project:id,name', 'weeklyReport:id,week_number', 'decider:id,name', 'payer:id,name'])
                    ->get()
                    ->map(fn (SupervisorPayment $payment) => [
                        'id' => $payment->id,
                        'kind' => $payment->kind->value,
                        'label' => $payment->label,
                        'amount' => (float) $payment->amount,
                        'status' => $payment->status->value,
                        'requested_at' => $payment->requested_at?->toIso8601String(),
                        'decided_at' => $payment->decided_at?->toIso8601String(),
                        'decider' => $payment->decider?->name,
                        'decision_note' => $payment->decision_note,
                        'paid_at' => $payment->paid_at?->toIso8601String(),
                    ])->values()->all()
                : [],
            'weeklyReports' => $project->weeklyReports()
                ->with(['supervisor:id,name', 'decider:id,name', 'payment:id,weekly_report_id,amount,status'])
                ->get()
                ->map(fn (WeeklyReport $report) => [
                    'id' => $report->id,
                    'week_number' => $report->week_number,
                    'week_start' => $report->week_start->toDateString(),
                    'notes' => $report->notes,
                    'links' => $report->links ?? [],
                    'status' => $report->status->value,
                    'supervisor' => $report->supervisor?->name,
                    'submitted_at' => $report->submitted_at?->toIso8601String(),
                    'decided_at' => $report->decided_at?->toIso8601String(),
                    'decider' => $report->decider?->name,
                    'decision_note' => $report->decision_note,
                    'payment_amount' => $seesMoney && $report->payment ? (float) $report->payment->amount : null,
                ])->values()->all(),
            'currentWeek' => $producing && $active ? [
                ...$this->weeklyReportService->weekOf($project),
                'is_report_day' => DailyFormSchedule::now()->dayOfWeek === (int) config('daiku.execution.weekly_report_day', 5),
            ] : null,
            'progress' => $progress,
            'threshold' => ProductionService::departureThreshold(),
            'departure' => $project->departure_approved_at ? [
                'approved_at' => $project->departure_approved_at->toIso8601String(),
                'approver' => $project->departureApprover()->value('name'),
            ] : null,
            'findings' => $project->siteFindings()
                ->with(['milestone:id,name', 'task:id,title,status,assignee_id,due_date', 'task.assignee:id,name', 'reporter:id,name'])
                ->get()
                ->map(fn (SiteFinding $finding) => [
                    'id' => $finding->id,
                    'description' => $finding->description,
                    'photo_url' => $finding->photo_url,
                    'status' => $finding->status->value,
                    'milestone' => $finding->milestone?->only(['id', 'name']),
                    'task' => $finding->task ? [
                        'id' => $finding->task->id,
                        'title' => $finding->task->title,
                        'status' => $finding->task->status->value,
                        'assignee' => $finding->task->assignee?->name,
                        'due_date' => $finding->task->due_date?->toDateString(),
                    ] : null,
                    'reporter' => $finding->reporter?->name,
                    'created_at' => $finding->created_at?->toIso8601String(),
                ])->values()->all(),
            'targetVsActual' => $phase->allowsTargets()
                ? [
                    'week_start' => $weekStart->toDateString(),
                    ...$this->productionService->targetVsActual($project, $weekStart, $weekStart->copy()->endOfWeek(Carbon::SUNDAY)),
                ]
                : null,
            'deadline' => $this->deadline($project),
            'can' => [
                'saveMeeting' => $active && $phase === ProjectPhase::Tm && $runsMeeting,
                'completeMeeting' => $active && $phase === ProjectPhase::Tm && $runsMeeting,
                'requestDp' => $active && $phase === ProjectPhase::Persiapan && $supervises,
                'decide' => $approves,
                'submitWeekly' => $active && $producing && $supervises,
                'approveDeparture' => $active && $phase === ProjectPhase::Produksi && $approves,
                'recordFinding' => $active && $phase === ProjectPhase::Pemasangan && $supervises,
                'resolveFinding' => ! $project->isClosed() && ($supervises || $approves),
                'manageTargets' => $managesTargets && $phase->allowsTargets(),
                'requestSettlement' => $project->status === ProjectStatus::Completed && $supervises,
            ],
        ];
    }

    /** @return array{end_date: string|null, warn_from: string|null, warning: bool, days_left: int|null} */
    private function deadline(Project $project): array
    {
        $from = $this->productionService->deadlineWarningFrom($project);
        $today = DailyFormSchedule::now()->startOfDay();

        return [
            'end_date' => $project->end_date?->toDateString(),
            'warn_from' => $from?->toDateString(),
            'warning' => $project->status === ProjectStatus::Active && $from !== null && $today->gte($from),
            'days_left' => $project->end_date ? (int) $today->diffInDays($project->end_date->copy()->startOfDay(), false) : null,
        ];
    }

    private function weekStart(?string $week): Carbon
    {
        $date = rescue(fn () => $week ? Carbon::parse($week, DailyFormSchedule::TIMEZONE) : null, null, false) ?? DailyFormSchedule::now();

        return $date->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
    }
}
