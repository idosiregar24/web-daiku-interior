<?php

namespace App\Services;

use App\Enums\MaterialOrderStatus;
use App\Enums\ProjectPhase;
use App\Enums\ProjectStatus;
use App\Enums\QuotationStatus;
use App\Enums\WeeklyReportStatus;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\SiteFinding;
use App\Models\SupervisorPayment;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Policies\ProjectPolicy;
use App\Support\DailyFormSchedule;
use App\Support\Quantity;
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
        private WorkItemService $workItemService,
        private ProductionService $productionService,
        private WageSplitService $wageSplitService,
        private ProjectStepsService $projectStepsService,
    ) {}

    /** Who gets the tab at all. */
    public static function canView(User $user, Project $project): bool
    {
        if ($user->hasAnyRole(['CEO', 'PM', 'QA', 'FINANCE', 'LOGISTICS', 'SUPERADMIN', 'ADMIN_PROYEK'])) {
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
        // Sprint 29 Sub 10 (B7) — progres berbobot (items with a wage) or actual activity.
        $progress = $project->progressFigure();
        $producing = $phase->isProducing();
        $weekStart = $this->weekStart($week);
        $usesWorkItems = $this->workItemService->hasWorkItems($project);
        $administers = $project->isAdministeredBy($user);
        // Sprint 29 Sub 09 — the pengecekan % per item (built once; the PM's dialog reads it too).
        $workCheck = $usesWorkItems && $producing && ($seesMoney || $administers) ? $this->workItemService->checkOverview($project, $seesMoney) : null;

        return [
            'phase' => $phase->value,
            // Sprint 33 — "Langkah Kerja": the running phase's work in the order it is done (top of Detail Proyek).
            'steps' => $this->projectStepsService->for($project, $user),
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
                // Sprint 34 — JADWAL (invited ahead) or LANGSUNG; the time and when the invitation went out.
                'meeting_time' => $meeting->meeting_time ? substr($meeting->meeting_time, 0, 5) : null,
                'mode' => $meeting->mode,
                'invited_at' => $meeting->invited_at?->toIso8601String(),
                'notes' => $meeting->notes,
                'attendees' => $meeting->attendees->map(fn ($row) => ['user_id' => $row->user_id, 'name' => $row->name, 'label' => $row->label])->values()->all(),
                'completed_at' => $meeting->completed_at?->toIso8601String(),
                'completer' => $meeting->completer?->name,
            ] : null,
            'meetingRequirements' => $phase === ProjectPhase::Tm ? $this->technicalMeetingService->requirements($project) : [],
            // The Pengawas's "bahan yang digunakan", planned right in the TM card.
            'meetingMaterials' => $phase === ProjectPhase::Tm
                // Sprint 29 Sub 11 — the Rencana Bahan too (rejected lines with their reason).
                ? $this->technicalMeetingService->plannedMaterials($project, true)
                    ->with(['material:id,name', 'unit:id,code'])
                    ->get()
                    ->map(fn (ProjectMaterial $line) => [
                        'id' => $line->id,
                        'material_id' => $line->material_id,
                        'name' => $line->display_name,
                        'qty' => $line->quantityLabel($line->qty_planned ?? 0),
                        'qty_planned' => (float) $line->qty_planned,
                        'qty_received' => (float) $line->qty_received,
                        'unit' => $line->unit?->code,
                        'source' => $line->source->value,
                        'channel' => $line->request_channel,
                        // E4 — ProjectMaterial::orderStatus(), worded by materialStatus() in the UI.
                        'order_status' => $line->orderStatus()->value,
                        'reject_reason' => $line->reject_reason,
                        'expected_at' => $line->expected_at?->toDateString(),
                        // A Rencana Bahan line: its planners' draft until submitted (E5); otherwise
                        // only a catalog line nothing arrived on can still be changed from the TM card.
                        'editable' => $line->request_channel === ProjectMaterial::CHANNEL_RENCANA
                            ? ($supervises || $administers) && $line->orderStatus() === MaterialOrderStatus::Direncanakan
                            : $line->material_id !== null && $line->request_channel === null && (float) $line->qty_received === 0.0,
                    ])->values()->all()
                : [],
            // What the RAB says the work is — the reference the Pengawas plans the materials from
            // (description + volume only, never prices). Only the PM side and the project's Pengawas.
            'meetingRab' => $phase === ProjectPhase::Tm && ($runsMeeting || $supervises) ? $this->rabReference($project) : [],
            // The TM form's pickers: attendees (every active account but technical ones), and E1's
            // default Tanggal Selesai (the project's, else the last timeline's end).
            'attendeeOptions' => $active && $phase === ProjectPhase::Tm && $runsMeeting
                ? User::query()->where('is_active', true)->whereDoesntHave('roles', fn ($role) => $role->where('name', 'SUPERADMIN'))->orderBy('name')->get(['id', 'name'])
                : [],
            'defaultEndDate' => $project->end_date?->toDateString() ?? $project->milestones()->reorder()->max('target_date'),
            'fee' => $seesMoney ? $this->supervisorPaymentService->summary($project) : null,
            'dpRequirements' => $phase === ProjectPhase::Persiapan
                // Sprint 27 P1a — task titles only for a viewer who sees the project's tasks (PRD §4.6).
                ? $this->supervisorPaymentService->dpRequirements($project, app(ProjectPolicy::class)->taskVisibility($user, $project) === 'all')
                : [],
            'payments' => $seesMoney
                ? $project->supervisorPayments()
                    ->with(['project:id,name', 'weeklyReport:id,week_number,sequence,status', 'decider:id,name', 'payer:id,name'])
                    ->get()
                    ->map(fn (SupervisorPayment $payment) => [
                        'id' => $payment->id,
                        'kind' => $payment->kind->value,
                        'label' => $payment->label,
                        'amount' => (float) $payment->amount,
                        'breakdown' => $payment->breakdown,
                        // Sprint 29 Sub 09 — decided with its pengecekan (WeeklyReportsCard), not on its own.
                        'via_report' => $payment->weeklyReport?->status === WeeklyReportStatus::Diajukan,
                        'status' => $payment->status->value,
                        'requested_at' => $payment->requested_at?->toIso8601String(),
                        'decided_at' => $payment->decided_at?->toIso8601String(),
                        'decider' => $payment->decider?->name,
                        'decision_note' => $payment->decision_note,
                        'paid_at' => $payment->paid_at?->toIso8601String(),
                    ])->values()->all()
                : [],
            'weeklyReports' => $project->weeklyReports()
                ->with(['supervisor:id,name', 'decider:id,name', 'payment', 'progressRows.workItem.quotationItem:id,description'])
                ->get()
                ->map(fn (WeeklyReport $report) => [
                    'id' => $report->id,
                    'week_number' => $report->week_number,
                    'week_start' => $report->week_start->toDateString(),
                    // Sprint 31 — several pengecekan a week: "Minggu ke-3 · Pengecekan ke-2".
                    'sequence' => $report->sequence,
                    'label' => $report->label(),
                    'notes' => $report->notes,
                    'links' => $report->links ?? [],
                    'status' => $report->status->value,
                    'supervisor' => $report->supervisor?->name,
                    'submitted_at' => $report->submitted_at?->toIso8601String(),
                    'decided_at' => $report->decided_at?->toIso8601String(),
                    'decider' => $report->decider?->name,
                    'decision_note' => $report->decision_note,
                    'payment_amount' => $seesMoney && $report->payment ? (float) $report->payment->amount : null,
                    // Sprint 29 Sub 09 — the request this pengecekan asked (money viewers only) and the % it added.
                    'payment' => $seesMoney && $report->payment ? [
                        'id' => $report->payment->id,
                        'kind' => $report->payment->kind->value,
                        'amount' => (float) $report->payment->amount,
                        'status' => $report->payment->status->value,
                        'breakdown' => $report->payment->breakdown,
                    ] : null,
                    'progress' => $report->progressRows->map(fn ($row) => [
                        // Sprint 38 — which item (its Progres Target mark on the PM's banner).
                        'item_id' => $row->project_work_item_id,
                        'description' => $row->workItem?->quotationItem?->description,
                        'percent' => (float) $row->percent,
                        'note' => $row->note,
                    ])->values()->all(),
                ])->values()->all(),
            'currentWeek' => $producing && $active ? [
                ...$this->weeklyReportService->weekOf($project),
                'is_report_day' => DailyFormSchedule::now()->dayOfWeek === (int) config('daiku.execution.weekly_report_day', 5),
                // Sprint 29 Sub 02 — may be sent any day of the week; from the report day on it is due.
                'is_due' => WeeklyReportService::isDue(),
                // Sprint 36 — sent on the send days only (else a draft), once a week.
                'open' => $this->weeklyReportService->canSubmit($project),
                'window_open' => WeeklyReportService::sendWindowOpen(),
                'send_days' => WeeklyReportService::sendDaysLabel(),
            ] : null,
            // Sprint 31 Sub 03 — "Jadwal per Item" (File C's light-blue cells): every viewer of the tab, from Persiapan.
            'workSchedule' => $usesWorkItems && $phase->isAtLeast(ProjectPhase::Persiapan) ? $this->workItemService->scheduleOverview($project) : null,
            // Sprint 31 — "Rekap Progres per Item" (File C as a table): the pengecekan viewers only.
            'progressRecap' => $usesWorkItems && $user->can('viewProgressRecap', $project) ? $this->workItemService->progressRecap($project) : null,
            'progress' => $progress['progress'],
            'progressBasis' => $progress['basis'],
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
            'usesWorkItems' => $usesWorkItems,
            // Sprint 29 Sub 08 — Upah Tukang per item: RAB prices + wages, for who sees the money or fills it.
            'workItems' => $seesMoney || $administers ? $this->workItemService->overview($project) : null,
            'workCheck' => $workCheck,
            // Sprint 32 — Pembagian Upah per Orang (File D): who sees the Upah Tukang; templates only for who fills it.
            'wageSplit' => $usesWorkItems && ($seesMoney || $administers) ? [
                ...$this->wageSplitService->overview($project),
                'levels' => $this->wageSplitService->levelOptions(),
                'templates' => $user->can('splitWages', $project) ? $this->wageSplitService->templateOptions() : [],
            ] : null,
            'can' => [
                'setWages' => $user->can('setWages', $project),
                // Sprint 35 — an item's dates come from its timelines: whoever manages the timelines links them.
                'linkTimelines' => $user->can('manageWork', $project) && ! $project->isClosed(),
                // Sprint 32 — Item RAB › Pembagian Upah.
                'splitWages' => $user->can('splitWages', $project),
                'saveMeeting' => $active && $phase === ProjectPhase::Tm && $user->can('editMeeting', $project),
                'completeMeeting' => $active && $phase === ProjectPhase::Tm && $runsMeeting,
                'requestDp' => $active && $phase === ProjectPhase::Persiapan && $supervises,
                'decide' => $approves,
                'submitWeekly' => $active && $producing && $supervises,
                'approveDeparture' => $active && $phase === ProjectPhase::Produksi && $approves,
                'recordFinding' => $active && $phase === ProjectPhase::Pemasangan && $user->can('recordFindings', $project),
                'resolveFinding' => ! $project->isClosed() && ($supervises || $approves),
                'manageTargets' => $managesTargets && $phase->allowsTargets(),
                // Sprint 29 Sub 09 (B5) — with wages per item: every item with a wage at 100 %.
                'requestSettlement' => $supervises && ($usesWorkItems
                    ? ($workCheck['summary']['all_done'] ?? $this->workItemService->allDone($project))
                    : $project->status === ProjectStatus::Completed),
                // Sprint 29 Sub 09 — the pengecekan draft: the Pengawas or the Admin Proyek (sending = submitWeekly).
                'fillWorkCheck' => $usesWorkItems && $user->can('fillWorkCheck', $project),
                // The TM card's material planner (same routes and policies as the Material tab).
                'planMaterials' => $active && $phase === ProjectPhase::Tm && $user->can('planMaterials', $project),
                'updateMaterials' => $active && $phase === ProjectPhase::Tm
                    && ($user->can('manageMaterials', $project) || $user->can('reviseMaterialPlan', $project)),
                'removeMaterials' => $active && $phase === ProjectPhase::Tm
                    && ($user->hasRole('LOGISTICS') || $user->can('reviseMaterialPlan', $project)),
                // Sprint 29 Sub 11 (E2/E3) — custom lines and "Ajukan ke Logistik" in the planner.
                'submitMaterialPlan' => $active && $phase === ProjectPhase::Tm && $user->can('planMaterials', $project)
                    && app(ProjectMaterialService::class)->plansThroughLogistics($project, $user),
            ],
        ];
    }

    /**
     * The approved RAB Proyek (+ approved RAB Tambahan) grouped per bagian pekerjaan.
     *
     * @return list<array{id: string, title: string, items: list<array{id: int, description: string, volume: string}>}>
     */
    private function rabReference(Project $project): array
    {
        $quotations = collect([$project->quotation])
            ->merge($project->addenda()->where('status', QuotationStatus::ClientApproved->value)->get())
            ->filter();

        return $quotations->flatMap(function (Quotation $quotation) {
            $quotation->load(['sections:id,quotation_id,name,sort_order', 'items']);

            return $quotation->items
                ->groupBy(fn (QuotationItem $item) => $item->section_id ?? 0)
                ->sortBy(fn ($items, $sectionId) => $quotation->sections->firstWhere('id', $sectionId)?->sort_order ?? -1)
                ->map(fn ($items, $sectionId) => [
                    'id' => "{$quotation->id}-{$sectionId}",
                    'title' => collect([$quotation->title(), $quotation->sections->firstWhere('id', $sectionId)?->name])->filter()->implode(' · '),
                    'items' => $items->map(fn (QuotationItem $item) => [
                        'id' => $item->id,
                        'description' => $item->description,
                        'volume' => trim(Quantity::format($item->qty).' '.($item->unit?->code ?? '')),
                    ])->values()->all(),
                ])->values();
        })->values()->all();
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
