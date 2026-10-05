<?php

namespace App\Services;

use App\Enums\DesignStatus;
use App\Enums\MilestoneStatus;
use App\Enums\OvertimeStatus;
use App\Enums\ProjectStatus;
use App\Enums\QaStatus;
use App\Enums\QuotationStatus;
use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\Design;
use App\Models\Milestone;
use App\Models\OvertimeRequest;
use App\Models\ProgressLog;
use App\Models\Project;
use App\Models\QaForm;
use App\Models\Quotation;
use App\Models\QuotationApproval;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Analytics – Per Divisi" (PRD §7.1, cell `P`) for the four divisions
 * that had no dashboard of their own (Sprint 9 decision #6): KPI Desain
 * (PRD §4.2), Dashboard Quotation (§4.3), Monitor Proyek — the §4.4
 * "Overdue Monitor" — and Dashboard QA (§4.6). Read-only, one public
 * method per widget like AnalyticsService (whose company-wide CEO queries
 * are deliberately not reused — security-standards.md §4).
 *
 * Portability (database-standards.md §1, the suite runs on SQLite): no
 * DATE_FORMAT()/DATEDIFF() — month buckets and day counts happen in PHP.
 * `date` columns are compared as half-open ranges (`>= day`, `< next
 * day`): SQLite stores a `date` cast as "Y-m-d H:i:s" text, MySQL a bare
 * DATE, and both compare correctly that way.
 */
class DivisionDashboardService
{
    /** The omset month filter offers this many months back, current month included. */
    public const RANGE_MONTHS = 24;

    /** PRD §4.2 "HOLD_CLIENT dan REVISI_CLIENT tidak menghitung delay (waktu ditangguhkan)". */
    private const SUSPENDED_DESIGN_STATUSES = [DesignStatus::HoldClient, DesignStatus::RevisiClient];

    /** QaFormService::review()'s audit actions — the only record of every QA decision. */
    private const QA_DECISION_ACTIONS = ['qa.approved', 'qa.rejected'];

    // ── Month range (KPI Desain omset filter) ─────────────────────────────

    /**
     * A `YYYY-MM` from/to pair from the query string, kept inside the
     * months the filter offers (monthOptions()) and swapped if reversed.
     * Missing or malformed input falls back to the last `$defaultMonths`
     * months — it's a read-only filter, so a bad bookmark renders the
     * default rather than an error.
     *
     * @return array{from: Carbon, to: Carbon} first moment of the first month, last moment of the last
     */
    public function monthRange(mixed $from, mixed $to, int $defaultMonths = 6): array
    {
        $latest = Carbon::today()->startOfMonth();
        $earliest = $latest->copy()->subMonths(self::RANGE_MONTHS - 1);
        $clamp = fn (Carbon $month): Carbon => match (true) {
            $month->lt($earliest) => $earliest->copy(),
            $month->gt($latest) => $latest->copy(),
            default => $month,
        };

        $end = $clamp($this->parseMonth($to) ?? $latest->copy());
        $start = $clamp($this->parseMonth($from) ?? $end->copy()->subMonths($defaultMonths - 1));

        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }

        return ['from' => $start, 'to' => $end->copy()->endOfMonth()];
    }

    /** @return list<array{value: string, label: string}> the selectable months, newest first */
    public function monthOptions(): array
    {
        $month = Carbon::today()->startOfMonth();
        $options = [];

        for ($i = 0; $i < self::RANGE_MONTHS; $i++) {
            $options[] = ['value' => $month->format('Y-m'), 'label' => $month->translatedFormat('M Y')];
            $month = $month->copy()->subMonth();
        }

        return $options;
    }

    // ── KPI Desain — PRD §4.2 ─────────────────────────────────────────────

    /**
     * PRD §4.2 "Status DELAY otomatis jika melewati deadline tanpa status
     * DONE". `delay_hari` is DesignDelayJob's running count (suspended
     * days already skipped, frozen at DONE_PRODUKSI — see
     * DesignService::recalculateDelays()). A design past its deadline and
     * not done also counts before the nightly job has added its first
     * day — unless it is currently suspended (HOLD_CLIENT/REVISI_CLIENT),
     * whose time "tidak menghitung delay".
     */
    public function isDesignDelayed(Design $design, CarbonInterface $today): bool
    {
        if ($design->delay_hari > 0) {
            return true;
        }

        return $design->deadline !== null
            && $design->deadline->lt($today)
            && $design->status !== DesignStatus::DoneProduksi
            && ! in_array($design->status, self::SUSPENDED_DESIGN_STATUSES, true);
    }

    /**
     * PRD §4.2 "KPI per PIC: Dashboard total project per desainer, on
     * schedule vs delay" — every design, all time. One row per active
     * DESIGNER (idle ones included: who has capacity is part of the
     * picture) plus any other user still PIC of a design; designs with no
     * PIC get a "Tanpa PIC" row. `summary` is the same designs, all PICs.
     * On schedule + delayed = total, so a design finished late stays
     * "delayed" (its delay_hari is frozen, not reset).
     *
     * @return array{summary: array{total: int, active: int, done: int, delayedActive: int, onTimeRate: int|null}, byPic: Collection<int, array<string, mixed>>}
     */
    public function designKpis(): array
    {
        $today = Carbon::today();
        $designs = Design::query()->get(['id', 'pic_id', 'status', 'deadline', 'delay_hari']);
        $byPic = $designs->groupBy(fn (Design $design) => $design->pic_id ?? 0);

        $people = User::role('DESIGNER')->where('is_active', true)->get(['id', 'name'])
            ->merge(User::whereIn('id', $byPic->keys()->filter())->get(['id', 'name']))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $rows = $people->map(fn (User $user) => $this->picKpi($user->id, $user->name, $byPic->get($user->id, collect()), $today));

        if ($byPic->has(0)) {
            $rows->push($this->picKpi(null, 'Tanpa PIC', $byPic->get(0), $today));
        }

        $done = $designs->filter(fn (Design $design) => $design->status === DesignStatus::DoneProduksi)->count();
        $delayed = $designs->filter(fn (Design $design) => $this->isDesignDelayed($design, $today));

        return [
            'summary' => [
                'total' => $designs->count(),
                'active' => $designs->count() - $done,
                'done' => $done,
                'delayedActive' => $delayed->filter(fn (Design $design) => $design->status !== DesignStatus::DoneProduksi)->count(),
                'onTimeRate' => $this->percentage($designs->count() - $delayed->count(), $designs->count()),
            ],
            'byPic' => $rows->values(),
        ];
    }

    /** @param  Collection<int, Design>  $designs */
    private function picKpi(?int $picId, string $name, Collection $designs, CarbonInterface $today): array
    {
        $total = $designs->count();
        $done = $designs->filter(fn (Design $design) => $design->status === DesignStatus::DoneProduksi)->count();
        $delayed = $designs->filter(fn (Design $design) => $this->isDesignDelayed($design, $today))->count();
        $delayDays = $designs->pluck('delay_hari')->filter(fn (int $days) => $days > 0);

        return [
            'picId' => $picId,
            'name' => $name,
            'total' => $total,
            'active' => $total - $done,
            'done' => $done,
            'onSchedule' => $total - $delayed,
            'delayed' => $delayed,
            'onTimeRate' => $this->percentage($total - $delayed, $total),
            // Average over the designs that actually accumulated delay days.
            'avgDelayDays' => $delayDays->isNotEmpty() ? round((float) $delayDays->avg(), 1) : null,
        ];
    }

    /**
     * PRD §4.2 "Tracking Omset Desain: Omset dan piutang client per bulan
     * per proyek desain" — projects that came out of a design (lead →
     * design → project), bucketed by the month the deal closed: a Project
     * is created exactly when the deal is confirmed
     * (LeadService::confirmDeal()), the same closing month the CEO's
     * Revenue vs Target uses.
     *
     * - omset = contract_value;
     * - terbayar = every DP + pelunasan received on the project's termins
     *   (termins are the client-payment ledger, TerminService);
     * - piutang = contract_value − terbayar: the whole unpaid contract,
     *   not only Σ termins.sisa_piutang. Termins are just the billing
     *   schedule and PM may not have scheduled all of it yet
     *   (TerminService allows < 100%) — counting scheduled termins only
     *   would make a project without termins look fully paid. The part
     *   no termin covers yet is reported on its own as `unscheduled`;
     * - CANCELLED projects are left out: a cancelled contract is neither
     *   omset nor a receivable.
     *
     * @return array{months: Collection<int, array<string, mixed>>, totals: array<string, float|int>, projects: Collection<int, array<string, mixed>>}
     */
    public function designRevenue(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = Project::query()
            ->where('status', '!=', ProjectStatus::Cancelled->value)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->whereHas('lead.design')
            ->with(['lead:id,client_name', 'lead.design:id,lead_id,pic_id', 'lead.design.pic:id,name'])
            ->withSum('termins as scheduled_amount', 'amount')
            ->withSum('termins as dp_received', 'dp_amount')
            ->withSum('termins as pelunasan_received', 'pelunasan')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (Project $project) {
                $contract = (float) $project->contract_value;
                $paid = (float) $project->dp_received + (float) $project->pelunasan_received;

                return [
                    'projectId' => $project->id,
                    'projectName' => $project->name,
                    'designId' => $project->lead?->design?->id,
                    'client' => $project->lead?->client_name ?? '—',
                    'pic' => $project->lead?->design?->pic?->name,
                    'status' => $project->status,
                    'closedAt' => $project->created_at->toDateString(),
                    'month' => $project->created_at->format('Y-m'),
                    'contractValue' => round($contract, 2),
                    'paid' => round($paid, 2),
                    'piutang' => round(max(0.0, $contract - $paid), 2),
                    'unscheduled' => round(max(0.0, $contract - (float) $project->scheduled_amount), 2),
                ];
            });

        $byMonth = $rows->groupBy('month');

        return [
            'months' => collect($this->monthBuckets($from, $to))
                ->map(function (string $label, string $month) use ($byMonth) {
                    $monthRows = $byMonth->get($month, collect());

                    return [
                        'month' => $month,
                        'label' => $label,
                        'omset' => round((float) $monthRows->sum('contractValue'), 2),
                        'piutang' => round((float) $monthRows->sum('piutang'), 2),
                        'projects' => $monthRows->count(),
                    ];
                })
                ->values(),
            'totals' => [
                'omset' => round((float) $rows->sum('contractValue'), 2),
                'paid' => round((float) $rows->sum('paid'), 2),
                'piutang' => round((float) $rows->sum('piutang'), 2),
                'unscheduled' => round((float) $rows->sum('unscheduled'), 2),
                'projects' => $rows->count(),
            ],
            'projects' => $rows->map(fn (array $row) => Arr::except($row, 'month'))->values(),
        ];
    }

    /**
     * "Desain Saya" for a DESIGNER — their own open designs (PIC = them)
     * due this calendar week (Senin–Minggu) and the ones already delayed
     * (same rule as the KPI; a delayed design is listed there only).
     *
     * @return array{openCount: int, dueThisWeek: Collection<int, array<string, mixed>>, overdue: Collection<int, array<string, mixed>>}
     */
    public function myDesigns(User $designer): array
    {
        $today = Carbon::today();
        $weekEnd = $today->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay();

        $designs = Design::query()
            ->where('pic_id', $designer->id)
            ->where('status', '!=', DesignStatus::DoneProduksi->value)
            ->with('lead:id,client_name')
            ->get(['id', 'lead_id', 'status', 'deadline', 'delay_hari']);

        [$delayed, $onTrack] = $designs->partition(fn (Design $design) => $this->isDesignDelayed($design, $today));

        $row = fn (Design $design) => [
            'id' => $design->id,
            'client' => $design->lead?->client_name ?? '—',
            'status' => $design->status,
            'deadline' => $design->deadline?->toDateString(),
            'daysLeft' => $design->deadline ? (int) round($today->diffInDays($design->deadline)) : null,
            // The job's count, or — before its first run past the deadline — the days since it.
            'delayDays' => $design->delay_hari > 0 || ! $design->deadline
                ? $design->delay_hari
                : $this->daysBetween($design->deadline, $today),
        ];

        return [
            'openCount' => $designs->count(),
            'dueThisWeek' => $onTrack
                ->filter(fn (Design $design) => $design->deadline?->between($today, $weekEnd))
                ->sortBy(fn (Design $design) => $design->deadline->timestamp)
                ->map($row)
                ->values(),
            'overdue' => $delayed->map($row)->sortByDesc('delayDays')->values(),
        ];
    }

    // ── Dashboard Quotation — PRD §4.3 ────────────────────────────────────

    /** @return array<string, int> every QuotationStatus (pipeline order), zero-filled */
    public function quotationStatusCounts(): array
    {
        $counts = Quotation::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return collect(QuotationStatus::cases())
            ->mapWithKeys(fn (QuotationStatus $status) => [$status->value => (int) ($counts[$status->value] ?? 0)])
            ->all();
    }

    /**
     * One work queue of the approval pipeline, longest-waiting first:
     * DRAFT ("Perlu dikerjakan" — the Estimator's turn), SUBMITTED
     * (waiting for PM / Asisten PM), WAITING_CEO (RAB Proyek, waiting for
     * the CEO) or APPROVED_INTERNAL (the Estimator still has to hand it
     * to Marketing). `since` is when
     * it entered the queue: created_at for a draft; updated_at for the
     * review states — exactly the submit / CEO-approval moment, since
     * nothing writes a quotation outside DRAFT except those status changes
     * (items and totals only change while DRAFT, QuotationService).
     * `lastRejection` (drafts only) is the latest decision when it was a
     * rejection — CEO, PM or CLIENT — i.e. why the draft came back.
     *
     * @return array{total: int, rows: Collection<int, array<string, mixed>>}
     */
    public function quotationQueue(QuotationStatus $status, int $limit = 10): array
    {
        $today = Carbon::today();
        $isDraft = $status === QuotationStatus::Draft;
        $sinceColumn = $isDraft ? 'created_at' : 'updated_at';
        $query = Quotation::query()->where('status', $status->value);

        $rows = (clone $query)
            ->with(array_filter([
                'lead:id,client_name',
                $isDraft ? 'approvals.approver:id,name' : null,
            ]))
            ->orderBy($sinceColumn)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(function (Quotation $quotation) use ($isDraft, $sinceColumn, $today) {
                $since = $quotation->{$sinceColumn};
                $latestDecision = $isDraft ? $quotation->approvals->sortByDesc('id')->first() : null;

                return [
                    'id' => $quotation->id,
                    'client' => $quotation->lead?->client_name ?? '—',
                    'totalAmount' => (float) $quotation->total_amount,
                    'version' => (int) $quotation->version,
                    'since' => $since->toIso8601String(),
                    'daysWaiting' => $this->daysBetween($since, $today),
                    'lastRejection' => $latestDecision?->status === 'REJECTED' ? [
                        'role' => $latestDecision->approver_role,
                        'by' => $latestDecision->approver?->name,
                        'note' => $latestDecision->note,
                        'at' => $latestDecision->created_at?->toIso8601String(),
                    ] : null,
                ];
            });

        return ['total' => $query->count(), 'rows' => $rows];
    }

    /**
     * "Nilai RAB per bulan" — one measure (quotations.total_amount) at two
     * pipeline points: `sent` puts each quotation in the month it last
     * went out to the client (`sent_at`, Marketing's "Kirim ke Client"),
     * `deal` in the month its deal closed (the Project's creation — the
     * same closing month the CEO's revenue chart and KPI Desain use).
     *
     * @return Collection<int, array{month: string, label: string, sent: float, sentCount: int, deal: float, dealCount: int}>
     */
    public function quotationMonthlyValue(int $months = 6): Collection
    {
        $from = Carbon::today()->startOfMonth()->subMonths($months - 1);
        $to = Carbon::today()->endOfMonth();

        $sent = Quotation::query()
            ->where('sent_at', '>=', $from)
            ->get(['id', 'total_amount', 'sent_at'])
            ->map(fn (Quotation $quotation) => [
                'month' => $quotation->sent_at->format('Y-m'),
                'amount' => (float) $quotation->total_amount,
            ]);

        $deals = Quotation::query()
            ->join('projects', 'projects.lead_id', '=', 'quotations.lead_id')
            ->where('quotations.status', QuotationStatus::ClientApproved->value)
            ->where('projects.created_at', '>=', $from)
            ->get(['quotations.total_amount', 'projects.created_at as closed_at'])
            ->map(fn (Quotation $quotation) => [
                'month' => Carbon::parse($quotation->closed_at)->format('Y-m'),
                'amount' => (float) $quotation->total_amount,
            ]);

        return collect($this->monthBuckets($from, $to))
            ->map(function (string $label, string $month) use ($sent, $deals) {
                $monthSent = $sent->where('month', $month);
                $monthDeals = $deals->where('month', $month);

                return [
                    'month' => $month,
                    'label' => $label,
                    'sent' => round((float) $monthSent->sum('amount'), 2),
                    'sentCount' => $monthSent->count(),
                    'deal' => round((float) $monthDeals->sum('amount'), 2),
                    'dealCount' => $monthDeals->count(),
                ];
            })
            ->values();
    }

    /**
     * Average time from a quotation's creation (the Client ACC that opened
     * its draft) to its first sending to the client (`first_sent_at`), over the
     * quotations first sent in the last `$months` months, plus how many
     * rejections each went through before that. The clock starts at
     * creation, not at submit, because the submit moment isn't stored:
     * QuotationService::submit() only flips the status and the next
     * decision overwrites updated_at, and quotation_approvals only records
     * decisions. So this is the full RAB lead time — drafting, approvals
     * and revision rounds.
     *
     * @return array{avgDays: float|null, count: int, avgRejections: float|null}
     */
    public function quotationTurnaround(int $months = 6): array
    {
        $from = Carbon::today()->startOfMonth()->subMonths($months - 1);

        $sentRows = Quotation::query()->where('first_sent_at', '>=', $from)->get(['id', 'created_at', 'first_sent_at']);
        $firstSent = $sentRows->mapWithKeys(fn (Quotation $quotation) => [$quotation->id => $quotation->first_sent_at]);
        $createdAt = $sentRows->pluck('created_at', 'id');

        if ($firstSent->isEmpty()) {
            return ['avgDays' => null, 'count' => 0, 'avgRejections' => null];
        }

        $rejections = QuotationApproval::query()
            ->whereIn('quotation_id', $firstSent->keys())
            ->where('status', 'REJECTED')
            ->get(['quotation_id', 'created_at'])
            ->groupBy('quotation_id');

        $hours = $firstSent->map(fn (Carbon $sentAt, int $id) => max(0.0, $createdAt[$id]->diffInHours($sentAt)));
        $rounds = $firstSent->map(fn (Carbon $sentAt, int $id) => $rejections->get($id, collect())
            ->filter(fn (QuotationApproval $rejection) => $rejection->created_at->lt($sentAt))
            ->count());

        return [
            'avgDays' => round((float) $hours->avg() / 24, 1),
            'count' => $firstSent->count(),
            'avgRejections' => round((float) $rounds->avg(), 1),
        ];
    }

    // ── Monitor Proyek (Overdue Monitor) — PRD §4.4 ───────────────────────

    /**
     * CEO (and the SUPERADMIN technical role) watch every PM's projects; a
     * PM only their own. ASISTEN_PM (Sprint 12 Sub 1) reads every project
     * for now — Sub 11 narrows it to the projects they're assigned to.
     */
    public function seesAllProjects(User $viewer): bool
    {
        return $viewer->hasAnyRole(['CEO', 'SUPERADMIN', 'ASISTEN_PM']);
    }

    /** @return EloquentCollection<int, User> PMs for the CEO's filter */
    public function projectManagers(): EloquentCollection
    {
        return User::role('PM')->orderBy('name')->get(['id', 'name']);
    }

    /**
     * The ACTIVE and ON_HOLD projects a viewer monitors: a PM only those
     * where `pm_id` is them (a pm filter from a PM is ignored); CEO every
     * PM's, optionally narrowed to one PM.
     *
     * @return EloquentCollection<int, Project>
     */
    public function monitoredProjects(User $viewer, ?int $pmId = null): EloquentCollection
    {
        $seesAll = $this->seesAllProjects($viewer);
        $latestLog = fn (string $column) => ProgressLog::select($column)
            ->whereColumn('project_id', 'projects.id')
            ->latest('log_date')
            ->latest('id')
            ->limit(1);

        return Project::query()
            ->whereIn('status', [ProjectStatus::Active->value, ProjectStatus::OnHold->value])
            ->when(! $seesAll, fn (Builder $query) => $query->where('pm_id', $viewer->id))
            ->when($seesAll && $pmId, fn (Builder $query) => $query->where('pm_id', $pmId))
            ->with(['pm:id,name', 'lead:id,client_name'])
            ->withCount([
                'milestones',
                'milestones as milestones_completed_count' => fn (Builder $query) => $query->where('status', MilestoneStatus::Completed->value),
                'tasks as open_tasks_count' => fn (Builder $query) => $query->where('status', '!=', TaskStatus::Done->value),
            ])
            ->addSelect([
                'latest_progress' => $latestLog('percentage'),
                'latest_progress_date' => $latestLog('log_date'),
            ])
            ->orderBy('name')
            ->get();
    }

    /**
     * PRD §4.4 "Overdue Monitor: Dashboard khusus PM untuk memantau task
     * yang melewati deadline", scoped by monitoredProjects(). Lateness —
     * overdue tasks, tasks due this week, milestones past or near their
     * target — is measured on ACTIVE projects only: an ON_HOLD project is
     * paused, the same rule that keeps it out of TaskOverdueJob and the
     * penalties (Sprint 9 decision #1). ON_HOLD projects still appear in
     * the progress table, and their QA hand-overs and overtime requests
     * still need a decision, so those stay listed.
     *
     * Overdue task = not DONE and (status OVER, or due_date before today).
     *
     * @return array<string, mixed>
     */
    public function projectMonitor(User $viewer, ?int $pmId = null): array
    {
        $today = Carbon::today();
        $weekEnd = $today->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay();
        $projects = $this->monitoredProjects($viewer, $pmId);
        $projectsById = $projects->keyBy('id');
        $projectIds = $projects->modelKeys();
        $activeIds = $projects->where('status', ProjectStatus::Active)->modelKeys();

        $overdueTasks = Task::query()
            ->whereIn('project_id', $activeIds)
            ->where('status', '!=', TaskStatus::Done->value)
            ->where(fn (Builder $query) => $query
                ->where('status', TaskStatus::Over->value)
                ->orWhere('due_date', '<', $today->toDateString()))
            ->with(['assignee:id,name', 'milestone:id,name'])
            ->orderBy('due_date')
            ->orderBy('id')
            ->get(['id', 'project_id', 'milestone_id', 'assignee_id', 'title', 'due_date', 'status', 'priority']);

        $dueSoon = Task::query()
            ->whereIn('project_id', $activeIds)
            ->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Over->value])
            ->where('due_date', '>=', $today->toDateString())
            ->where('due_date', '<', $weekEnd->copy()->addDay()->toDateString())
            ->with(['assignee:id,name', 'milestone:id,name'])
            ->orderBy('due_date')
            ->orderBy('id')
            ->get(['id', 'project_id', 'milestone_id', 'assignee_id', 'title', 'due_date', 'status', 'priority'])
            ->map(fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'projectId' => $task->project_id,
                'projectName' => $projectsById->get($task->project_id)?->name ?? '—',
                'assignee' => $task->assignee?->name,
                'milestone' => $task->milestone?->name,
                'dueDate' => $task->due_date->toDateString(),
                'isToday' => $task->due_date->isSameDay($today),
                'status' => $task->status,
                'priority' => $task->priority,
            ]);

        $milestones = $this->attentionMilestones($projectIds, $activeIds, $today, $projectsById);

        $overtime = OvertimeRequest::query()
            ->whereIn('project_id', $projectIds)
            ->where('status', OvertimeStatus::Pending->value)
            ->with('staff:id,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'staff_id', 'project_id', 'work_date', 'hours', 'total_amount', 'reason', 'created_at'])
            ->map(fn (OvertimeRequest $request) => [
                'id' => $request->id,
                'staff' => $request->staff?->name,
                'projectId' => $request->project_id,
                'projectName' => $projectsById->get($request->project_id)?->name ?? '—',
                'workDate' => $request->work_date->toDateString(),
                'hours' => (float) $request->hours,
                'totalAmount' => (float) $request->total_amount,
                'reason' => $request->reason,
                'daysWaiting' => $this->daysBetween($request->created_at, $today),
            ]);

        $overdueByProject = $overdueTasks->countBy('project_id');

        return [
            'stats' => [
                'projects' => $projects->count(),
                'onHold' => $projects->where('status', ProjectStatus::OnHold)->count(),
                'overdueTasks' => $overdueTasks->count(),
                'dueToday' => $dueSoon->where('isToday', true)->count(),
                'dueThisWeek' => $dueSoon->count(),
                'milestonesOverdue' => $milestones->where('reason', 'OVERDUE')->count(),
                'qaWaiting' => $milestones->where('reason', 'QA_WAITING')->count(),
                'pendingOvertime' => $overtime->count(),
            ],
            'projects' => $projects
                ->map(fn (Project $project) => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'client' => $project->lead?->client_name,
                    'status' => $project->status,
                    'pm' => $project->pm?->name,
                    'progress' => $project->latest_progress === null ? null : (int) $project->latest_progress,
                    'progressDate' => $project->latest_progress_date === null
                        ? null
                        : Carbon::parse($project->latest_progress_date)->toDateString(),
                    'milestonesCompleted' => (int) $project->milestones_completed_count,
                    'milestonesTotal' => (int) $project->milestones_count,
                    'openTasks' => (int) $project->open_tasks_count,
                    'overdueTasks' => (int) $overdueByProject->get($project->id, 0),
                ])
                ->sortBy([['overdueTasks', 'desc'], ['name', 'asc']])
                ->values(),
            'overdue' => $this->groupOverdueTasks($overdueTasks, $projectsById, $today),
            'dueSoon' => $dueSoon->values(),
            'milestones' => $milestones,
            'pendingOvertime' => $overtime->values(),
        ];
    }

    /**
     * Overdue tasks → project → assignee, the latest one first at every
     * level (the PM chases the worst delays first).
     *
     * @param  EloquentCollection<int, Task>  $tasks
     * @param  Collection<int, Project>  $projectsById
     * @return Collection<int, array<string, mixed>>
     */
    private function groupOverdueTasks(EloquentCollection $tasks, Collection $projectsById, CarbonInterface $today): Collection
    {
        return $tasks
            ->groupBy('project_id')
            ->map(function (Collection $projectTasks, int $projectId) use ($projectsById, $today) {
                $assignees = $projectTasks
                    ->groupBy('assignee_id')
                    ->map(function (Collection $own) use ($today) {
                        $rows = $own
                            ->map(fn (Task $task) => [
                                'id' => $task->id,
                                'title' => $task->title,
                                'milestone' => $task->milestone?->name,
                                'dueDate' => $task->due_date->toDateString(),
                                'daysLate' => $this->daysBetween($task->due_date, $today),
                                'status' => $task->status,
                                'priority' => $task->priority,
                            ])
                            ->sortByDesc('daysLate')
                            ->values();

                        return [
                            'id' => $own->first()->assignee_id,
                            'name' => $own->first()->assignee?->name ?? '—',
                            'maxDaysLate' => (int) $rows->max('daysLate'),
                            'tasks' => $rows,
                        ];
                    })
                    ->sortByDesc('maxDaysLate')
                    ->values();

                $project = $projectsById->get($projectId);

                return [
                    'projectId' => $projectId,
                    'projectName' => $project?->name ?? '—',
                    'client' => $project?->lead?->client_name,
                    'total' => $projectTasks->count(),
                    'maxDaysLate' => (int) $assignees->max('maxDaysLate'),
                    'assignees' => $assignees,
                ];
            })
            ->sortByDesc('maxDaysLate')
            ->values();
    }

    /**
     * Milestones that need the PM's eye: QA_WAITING (handed to QA, still
     * open — any monitored project), and on ACTIVE projects OVERDUE or
     * still PENDING/IN_PROGRESS with a target date within 7 days (a past
     * target not yet flagged by MilestoneOverdueJob counts as overdue).
     *
     * @param  array<int, int>  $projectIds
     * @param  array<int, int>  $activeIds
     * @param  Collection<int, Project>  $projectsById
     * @return Collection<int, array<string, mixed>>
     */
    private function attentionMilestones(array $projectIds, array $activeIds, CarbonInterface $today, Collection $projectsById): Collection
    {
        $rank = ['OVERDUE' => 0, 'QA_WAITING' => 1, 'DUE_SOON' => 2];

        return Milestone::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $q) => $q
                    ->whereIn('project_id', $projectIds)
                    ->where('status', MilestoneStatus::QaWaiting->value))
                ->orWhere(fn (Builder $q) => $q
                    ->whereIn('project_id', $activeIds)
                    ->where(fn (Builder $late) => $late
                        ->where('status', MilestoneStatus::Overdue->value)
                        ->orWhere(fn (Builder $soon) => $soon
                            ->whereIn('status', [MilestoneStatus::Pending->value, MilestoneStatus::InProgress->value])
                            ->where('target_date', '<', $today->copy()->addDays(8)->toDateString())))))
            ->orderBy('target_date')
            ->orderBy('id')
            ->get(['id', 'project_id', 'name', 'status', 'target_date'])
            ->map(function (Milestone $milestone) use ($today, $projectsById) {
                $reason = match (true) {
                    $milestone->status === MilestoneStatus::QaWaiting => 'QA_WAITING',
                    $milestone->status === MilestoneStatus::Overdue, $milestone->target_date->lt($today) => 'OVERDUE',
                    default => 'DUE_SOON',
                };

                return [
                    'id' => $milestone->id,
                    'name' => $milestone->name,
                    'projectId' => $milestone->project_id,
                    'projectName' => $projectsById->get($milestone->project_id)?->name ?? '—',
                    'status' => $milestone->status,
                    'targetDate' => $milestone->target_date->toDateString(),
                    'daysLeft' => (int) round($today->diffInDays($milestone->target_date)),
                    'reason' => $reason,
                ];
            })
            ->sortBy([
                fn (array $a, array $b) => $rank[$a['reason']] <=> $rank[$b['reason']],
                fn (array $a, array $b) => $a['targetDate'] <=> $b['targetDate'],
            ])
            ->values();
    }

    // ── Dashboard QA — PRD §4.6 ───────────────────────────────────────────
    // Project/milestone level only: nothing here reads tasks ("QA tidak
    // bisa melihat detail task tukang — hanya ringkasan progres milestone").

    /**
     * The review queue: PENDING forms, longest-waiting first. Waiting since
     * = the latest hand-over to QA. MilestoneService::markDone() moves the
     * milestone to QA_WAITING (its updated_at) and re-opens the same form
     * after a rejection, so the latest of form created / last review /
     * milestone updated is when QA got it back — a PM editing the
     * milestone meanwhile can only make it look newer, never older.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function qaPendingForms(): Collection
    {
        $today = Carbon::today();

        return QaForm::query()
            ->where('status', QaStatus::Pending->value)
            ->with(['project:id,name', 'milestone:id,name,target_date,updated_at'])
            ->addSelect(['project_progress' => ProgressLog::select('percentage')
                ->whereColumn('project_id', 'qa_forms.project_id')
                ->latest('log_date')
                ->latest('id')
                ->limit(1)])
            ->get()
            ->map(function (QaForm $form) use ($today) {
                // qa_forms has no `updated_at`, so Eloquent leaves created_at a raw string.
                $since = collect([Carbon::parse($form->created_at), $form->reviewed_at, $form->milestone?->updated_at])
                    ->filter()
                    ->max();

                return [
                    'id' => $form->id,
                    'projectId' => $form->project_id,
                    'projectName' => $form->project?->name ?? '—',
                    'projectProgress' => $form->project_progress === null ? null : (int) $form->project_progress,
                    'milestoneName' => $form->milestone?->name ?? '—',
                    'milestoneTargetDate' => $form->milestone?->target_date?->toDateString(),
                    'round' => $form->rejection_count + 1,
                    'waitingSince' => $since->toIso8601String(),
                    'daysWaiting' => $this->daysBetween($since, $today),
                ];
            })
            ->sortBy('waitingSince')
            ->values();
    }

    /**
     * Decisions this month and review speed. QA decisions are counted from
     * the audit trail (QaFormService::review() records every approve and
     * reject) because a form keeps only its latest outcome — a milestone
     * rejected on the 3rd and approved on the 10th leaves no rejection on
     * the form itself. Approvals are final, so those also match
     * `qa_forms.reviewed_at`.
     *
     * Review time = form creation → its first decision, over forms first
     * decided in the last `$windowDays` days. First round only: when a
     * rejected milestone is re-submitted nothing records the moment, so
     * later rounds have no reliable start.
     *
     * @return array{monthLabel: string, approvedThisMonth: int, rejectedThisMonth: int, rejectionRate: int|null, avgReviewHours: float|null, reviewSample: int, reviewWindowDays: int}
     */
    public function qaStats(int $windowDays = 90): array
    {
        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth();

        $approved = QaForm::query()
            ->where('status', QaStatus::Approved->value)
            ->where('reviewed_at', '>=', $monthStart)
            ->count();
        $rejected = AuditLog::query()
            ->where('model_type', 'QaForm')
            ->where('action', 'qa.rejected')
            ->where('created_at', '>=', $monthStart)
            ->count();

        $windowStart = $today->copy()->subDays($windowDays);
        $firstDecisions = AuditLog::query()
            ->where('model_type', 'QaForm')
            ->whereIn('action', self::QA_DECISION_ACTIONS)
            ->groupBy('model_id')
            ->selectRaw('model_id, MIN(created_at) as first_decided_at')
            ->get()
            ->mapWithKeys(fn (AuditLog $row) => [(int) $row->model_id => Carbon::parse($row->first_decided_at)])
            ->filter(fn (Carbon $decidedAt) => $decidedAt->gte($windowStart));

        $createdAt = QaForm::whereIn('id', $firstDecisions->keys())->pluck('created_at', 'id');
        $hours = $firstDecisions
            ->filter(fn (Carbon $decidedAt, int $id) => isset($createdAt[$id]))
            ->map(fn (Carbon $decidedAt, int $id) => max(0.0, Carbon::parse($createdAt[$id])->diffInHours($decidedAt)));

        return [
            'monthLabel' => $monthStart->translatedFormat('F Y'),
            'approvedThisMonth' => $approved,
            'rejectedThisMonth' => $rejected,
            'rejectionRate' => $this->percentage($rejected, $approved + $rejected),
            'avgReviewHours' => $hours->isNotEmpty() ? round((float) $hours->avg(), 1) : null,
            'reviewSample' => $hours->count(),
            'reviewWindowDays' => $windowDays,
        ];
    }

    /**
     * PRD §4.6 "Jika QA reject dua kali berturut-turut pada milestone yang
     * sama, CEO mendapat notifikasi" — the forms that got there, still-open
     * ones first, then most rejections, most recently reviewed.
     *
     * @return array{total: int, rows: Collection<int, array<string, mixed>>}
     */
    public function qaRepeatRejections(int $limit = 10): array
    {
        $forms = QaForm::query()
            ->where('rejection_count', '>=', 2)
            ->with(['project:id,name', 'milestone:id,name'])
            ->get(['id', 'project_id', 'milestone_id', 'status', 'rejection_count', 'notes', 'reviewed_at']);

        return [
            'total' => $forms->count(),
            'rows' => $forms
                ->sortBy([
                    fn (QaForm $a, QaForm $b) => ($a->status === QaStatus::Approved) <=> ($b->status === QaStatus::Approved),
                    fn (QaForm $a, QaForm $b) => $b->rejection_count <=> $a->rejection_count,
                    fn (QaForm $a, QaForm $b) => $b->reviewed_at <=> $a->reviewed_at,
                ])
                ->take($limit)
                ->map(fn (QaForm $form) => [
                    'id' => $form->id,
                    'projectId' => $form->project_id,
                    'projectName' => $form->project?->name ?? '—',
                    'milestoneName' => $form->milestone?->name ?? '—',
                    'status' => $form->status,
                    'rejectionCount' => (int) $form->rejection_count,
                    'notes' => $form->notes,
                    'reviewedAt' => $form->reviewed_at?->toIso8601String(),
                ])
                ->values(),
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @return array<string, string> 'Y-m' => "Sep 2026" for every month in the range, oldest first */
    private function monthBuckets(CarbonInterface $from, CarbonInterface $to): array
    {
        $buckets = [];

        for ($month = Carbon::instance($from)->startOfMonth(); $month->lte($to); $month->addMonth()) {
            $buckets[$month->format('Y-m')] = $month->translatedFormat('M Y');
        }

        return $buckets;
    }

    private function parseMonth(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            return null;
        }

        // `!` resets the unparsed fields — without it the 29th–31st overflow into the next month.
        return Carbon::createFromFormat('!Y-m', $value);
    }

    /** Whole calendar days from `$from` to `$today`, never negative. */
    private function daysBetween(CarbonInterface $from, CarbonInterface $today): int
    {
        return max(0, (int) round(Carbon::instance($from)->startOfDay()->diffInDays(Carbon::instance($today)->startOfDay())));
    }

    private function percentage(int $part, int $whole): ?int
    {
        return $whole > 0 ? (int) round($part / $whole * 100) : null;
    }
}
