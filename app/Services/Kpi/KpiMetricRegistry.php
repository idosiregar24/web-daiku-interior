<?php

namespace App\Services\Kpi;

use App\Enums\KpiDirection;
use App\Enums\LeadStatus;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\QaStatus;
use App\Models\AuditLog;
use App\Models\Design;
use App\Models\Lead;
use App\Models\Milestone;
use App\Models\PipelineLog;
use App\Models\Project;
use App\Models\QaForm;
use App\Models\Quotation;
use App\Models\QuotationApproval;
use App\Models\QuotationItemReview;
use App\Models\Termin;
use App\Models\User;
use App\Services\DivisionDashboardService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * SDM (Sprint 10, §3.3) — the AUTO KPI metrics: every `metric_key` a KPI
 * indicator may reference, with its Indonesian label, unit, default
 * direction, the roles/positions it suits, and a calculator for one
 * month of one user's activity.
 *
 * Calculators take a half-open month window [`$from`, `$to`) and return
 * null when there is nothing to measure (e.g. a designer with no design
 * due that month) — KpiService then leaves the indicator unscored and
 * redistributes its weight. Counts return 0, which is a real value.
 *
 * Operational tables keep only the CURRENT state of most records (no
 * status history beyond pipeline_logs / audit_logs), so a few metrics
 * read today's state for a past month (noted per metric). That's why a
 * month is computed while OPEN and frozen when HR closes it.
 *
 * Portability (database-standards.md §1, tests run on SQLite): dates are
 * compared as strings/half-open ranges, day math happens in PHP — same
 * approach as DivisionDashboardService, whose design-delay rule is reused
 * through its public isDesignDelayed().
 */
class KpiMetricRegistry
{
    /**
     * @var array<string, array{label: string, unit: string, direction: KpiDirection, roles: list<string>, positionHints: list<string>, description: string}>
     */
    private const METRICS = [
        'design_on_schedule_rate' => [
            'label' => '% desain tepat jadwal',
            'unit' => '%',
            'direction' => KpiDirection::HigherBetter,
            'roles' => ['DESIGNER'],
            'positionHints' => ['desain', 'arsitek', 'drafter'],
            'description' => 'Desain (sebagai PIC) yang deadline-nya jatuh di bulan ini dan tidak delay.',
        ],
        'design_avg_delay_days' => [
            'label' => 'Rata-rata hari delay desain',
            'unit' => 'hari',
            'direction' => KpiDirection::LowerBetter,
            'roles' => ['DESIGNER'],
            'positionHints' => ['desain', 'arsitek', 'drafter'],
            'description' => 'Rata-rata hari delay desain (sebagai PIC) yang deadline-nya jatuh di bulan ini.',
        ],
        'design_client_acc_count' => [
            'label' => 'Jumlah desain ACC klien',
            'unit' => 'jumlah',
            'direction' => KpiDirection::HigherBetter,
            'roles' => ['DESIGNER'],
            'positionHints' => ['desain', 'arsitek'],
            'description' => 'Desain (sebagai PIC) yang di-ACC klien pada bulan ini.',
        ],
        'quotation_turnaround_days' => [
            'label' => 'Rata-rata turnaround quotation',
            'unit' => 'hari',
            'direction' => KpiDirection::LowerBetter,
            'roles' => ['ESTIMATOR'],
            'positionHints' => ['estimator'],
            'description' => 'Hari dari quotation dibuat sampai pertama kali dikirim ke klien (ACC PM), untuk quotation yang terkirim bulan ini.',
        ],
        'quotation_sent_count' => [
            'label' => 'Jumlah quotation terkirim',
            'unit' => 'jumlah',
            'direction' => KpiDirection::HigherBetter,
            'roles' => ['ESTIMATOR'],
            'positionHints' => ['estimator'],
            'description' => 'Quotation buatan sendiri yang pertama kali dikirim ke klien pada bulan ini.',
        ],
        // Sprint 12 #9 — from the per-item RAB review of sub-plan 04.
        'estimator_first_pass_rate' => [
            'label' => '% item RAB lolos review pertama',
            'unit' => '%',
            'direction' => KpiDirection::HigherBetter,
            'roles' => ['ESTIMATOR'],
            'positionHints' => ['estimator'],
            'description' => 'Dari item RAB buatan sendiri yang direview PM di versi pertama bulan ini, persentase yang langsung ✔.',
        ],
        'estimator_returned_count' => [
            'label' => 'Jumlah RAB dikembalikan',
            'unit' => 'jumlah',
            'direction' => KpiDirection::LowerBetter,
            'roles' => ['ESTIMATOR'],
            'positionHints' => ['estimator'],
            'description' => 'Keputusan "kembalikan" PM / Asisten PM atau CEO atas RAB buatan sendiri bulan ini (tanpa review bulan ini → tidak ada data).',
        ],
        'pm_review_escaped_count' => [
            'label' => 'RAB di-ACC lalu dikembalikan CEO',
            'unit' => 'jumlah',
            'direction' => KpiDirection::LowerBetter,
            'roles' => ['PM', 'ASISTEN_PM'],
            'positionHints' => ['project manager', 'pm', 'asisten'],
            'description' => 'Dari RAB yang di-ACC sendiri di tahap PM dan diputuskan CEO bulan ini, jumlah yang dikembalikan CEO.',
        ],
        'lead_new_count' => [
            'label' => 'Jumlah lead baru',
            'unit' => 'jumlah',
            'direction' => KpiDirection::HigherBetter,
            'roles' => ['MARKETING'],
            'positionHints' => ['marketing'],
            'description' => 'Lead yang di-assign ke karyawan dan masuk pada bulan ini.',
        ],
        'lead_conversion_rate' => [
            'label' => 'Konversi lead → deal',
            'unit' => '%',
            'direction' => KpiDirection::HigherBetter,
            'roles' => ['MARKETING'],
            'positionHints' => ['marketing'],
            'description' => 'Dari lead yang diputuskan bulan ini (deal atau lost), persentase yang deal.',
        ],
        'lead_overdue_followup_count' => [
            'label' => 'Jumlah follow-up terlambat',
            'unit' => 'jumlah',
            'direction' => KpiDirection::LowerBetter,
            'roles' => ['MARKETING'],
            'positionHints' => ['marketing'],
            'description' => 'Lead yang masih FOLLOW UP padahal tanggal follow-up-nya (di bulan ini) sudah lewat.',
        ],
        'milestone_on_time_rate' => [
            'label' => '% milestone tepat waktu',
            'unit' => '%',
            'direction' => KpiDirection::HigherBetter,
            'roles' => ['PM'],
            'positionHints' => ['project manager', 'pm'],
            'description' => 'Milestone proyek yang dipegang, bertarget di bulan ini, yang lolos QA paling lambat pada tanggal targetnya.',
        ],
        'qa_first_pass_rate' => [
            'label' => '% QA lolos pertama kali',
            'unit' => '%',
            'direction' => KpiDirection::HigherBetter,
            'roles' => ['PM'],
            'positionHints' => ['project manager', 'pm'],
            'description' => 'Form QA proyek yang dipegang, diputuskan pertama kali bulan ini, yang langsung disetujui.',
        ],
        'project_delay_count' => [
            'label' => 'Jumlah proyek delay',
            'unit' => 'jumlah',
            'direction' => KpiDirection::LowerBetter,
            'roles' => ['PM'],
            'positionHints' => ['project manager', 'pm'],
            'description' => 'Proyek yang dipegang dengan milestone lewat target dan belum lolos QA pada akhir bulan.',
        ],
        'qa_forms_processed_count' => [
            'label' => 'Jumlah form QA diproses',
            'unit' => 'jumlah',
            'direction' => KpiDirection::HigherBetter,
            'roles' => ['QA'],
            'positionHints' => ['quality', 'qa'],
            'description' => 'Keputusan QA (setuju/tolak) yang diambil karyawan pada bulan ini.',
        ],
        'termin_on_time_rate' => [
            'label' => '% termin tertagih tepat waktu',
            'unit' => '%',
            'direction' => KpiDirection::HigherBetter,
            'roles' => ['FINANCE'],
            'positionHints' => ['finance', 'keuangan'],
            'description' => 'Termin jatuh tempo bulan ini yang lunas paling lambat pada tanggal jadwalnya (angka tim Finance, bukan per orang).',
        ],
    ];

    public function __construct(private DivisionDashboardService $dashboards) {}

    public function has(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::METRICS);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::METRICS);
    }

    public function label(string $key): ?string
    {
        return self::METRICS[$key]['label'] ?? null;
    }

    public function unit(?string $key): ?string
    {
        return $key ? (self::METRICS[$key]['unit'] ?? null) : null;
    }

    /**
     * For the template editor's metric picker.
     *
     * @return list<array{key: string, label: string, unit: string, direction: string, roles: list<string>, positionHints: list<string>, description: string}>
     */
    public function options(): array
    {
        return collect(self::METRICS)
            ->map(fn (array $metric, string $key) => [
                'key' => $key,
                'label' => $metric['label'],
                'unit' => $metric['unit'],
                'direction' => $metric['direction']->value,
                'roles' => $metric['roles'],
                'positionHints' => $metric['positionHints'],
                'description' => $metric['description'],
            ])
            ->values()
            ->all();
    }

    /** One user's value for one month [`$from`, `$to`). */
    public function compute(string $key, User $user, CarbonInterface $from, CarbonInterface $to): ?float
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("Metrik KPI tidak dikenal: {$key}");
        }

        $from = Carbon::instance($from)->startOfDay();
        $to = Carbon::instance($to)->startOfDay();
        // "As of" moment for state-based checks: the month's end, or today for the running month.
        $asOf = Carbon::today()->lt($to) ? Carbon::today() : $to->copy();

        $value = match ($key) {
            'design_on_schedule_rate' => $this->designOnScheduleRate($user, $from, $to, $asOf),
            'design_avg_delay_days' => $this->designAverageDelay($user, $from, $to),
            'design_client_acc_count' => $this->designClientAccCount($user, $from, $to),
            'quotation_turnaround_days' => $this->quotationTurnaround($user, $from, $to),
            'quotation_sent_count' => (float) $this->quotationsFirstSent($user, $from, $to)->count(),
            'estimator_first_pass_rate' => $this->estimatorFirstPassRate($user, $from, $to),
            'estimator_returned_count' => $this->estimatorReturnedCount($user, $from, $to),
            'pm_review_escaped_count' => $this->pmReviewEscapedCount($user, $from, $to),
            'lead_new_count' => $this->leadNewCount($user, $from, $to),
            'lead_conversion_rate' => $this->leadConversionRate($user, $from, $to),
            'lead_overdue_followup_count' => $this->leadOverdueFollowUps($user, $from, $asOf),
            'milestone_on_time_rate' => $this->milestoneOnTimeRate($user, $from, $asOf),
            'qa_first_pass_rate' => $this->qaFirstPassRate($user, $from, $to),
            'project_delay_count' => $this->projectDelayCount($user, $from, $to, $asOf),
            'qa_forms_processed_count' => $this->qaFormsProcessed($user, $from, $to),
            'termin_on_time_rate' => $this->terminOnTimeRate($from, $asOf),
        };

        return $value === null ? null : round($value, 2);
    }

    // ── Desain (PIC) ──────────────────────────────────────────────────────

    /** @return Collection<int, Design> designs the user is PIC of, due within the month */
    private function designsDue(User $user, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return Design::query()
            ->where('pic_id', $user->id)
            ->where('deadline', '>=', $from->toDateString())
            ->where('deadline', '<', $to->toDateString())
            ->get(['id', 'quotation_id', 'pic_id', 'status', 'client_acc', 'deadline', 'delay_hari']);
    }

    /** Uses today's delay_hari/status (no history is kept) — DivisionDashboardService's delay rule. */
    private function designOnScheduleRate(User $user, CarbonInterface $from, CarbonInterface $to, CarbonInterface $asOf): ?float
    {
        $designs = $this->designsDue($user, $from, $to);

        if ($designs->isEmpty()) {
            return null;
        }

        $onSchedule = $designs->reject(fn (Design $design) => $this->dashboards->isDesignDelayed($design, $asOf))->count();

        return $onSchedule / $designs->count() * 100;
    }

    private function designAverageDelay(User $user, CarbonInterface $from, CarbonInterface $to): ?float
    {
        $designs = $this->designsDue($user, $from, $to);

        return $designs->isEmpty() ? null : (float) $designs->avg(fn (Design $design) => max(0, (int) $design->delay_hari));
    }

    private function designClientAccCount(User $user, CarbonInterface $from, CarbonInterface $to): float
    {
        return (float) Design::query()
            ->where('pic_id', $user->id)
            ->where('client_acc', true)
            ->where('acc_date', '>=', $from->toDateString())
            ->where('acc_date', '<', $to->toDateString())
            ->count();
    }

    // ── Quotation (Estimator) ─────────────────────────────────────────────

    /**
     * The user's quotations first sent to the client (`first_sent_at` —
     * Marketing's first "Kirim ke Client", Sprint 12) within the month — the same "sent" moment as
     * DivisionDashboardService::quotationTurnaround(), scoped to the
     * creator (that method is company-wide).
     *
     * @return Collection<int, Carbon> quotation id → first sent at
     */
    private function quotationsFirstSent(User $user, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return Quotation::query()
            ->where('created_by', $user->id)
            ->where('first_sent_at', '>=', $from)
            ->where('first_sent_at', '<', $to)
            ->pluck('first_sent_at', 'id');
    }

    private function quotationTurnaround(User $user, CarbonInterface $from, CarbonInterface $to): ?float
    {
        $sent = $this->quotationsFirstSent($user, $from, $to);

        if ($sent->isEmpty()) {
            return null;
        }

        $createdAt = Quotation::whereIn('id', $sent->keys())->pluck('created_at', 'id');
        $hours = $sent
            ->filter(fn (Carbon $sentAt, int $id) => isset($createdAt[$id]))
            ->map(fn (Carbon $sentAt, int $id) => max(0.0, Carbon::parse($createdAt[$id])->diffInHours($sentAt)));

        return $hours->isEmpty() ? null : (float) $hours->avg() / 24;
    }

    // ── RAB review (Sprint 12 #9) ─────────────────────────────────────────

    /**
     * Σ ✔ ÷ Σ items in the PM / Asisten PM review of VERSION 1 of the
     * user's quotations (`created_by` = the Estimator who built it), by the
     * review's date. Later versions — fixes after a return — don't count.
     */
    private function estimatorFirstPassRate(User $user, CarbonInterface $from, CarbonInterface $to): ?float
    {
        $verdicts = QuotationItemReview::query()
            ->whereIn('quotation_id', Quotation::where('created_by', $user->id)->select('id'))
            ->where('version', 1)
            ->where('stage', QuotationItemReview::STAGE_PM)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->pluck('verdict');

        if ($verdicts->isEmpty()) {
            return null;
        }

        return $verdicts->filter(fn (string $verdict) => $verdict === QuotationItemReview::VERDICT_OK)->count() / $verdicts->count() * 100;
    }

    /**
     * "Kembalikan" decisions (PM / Asisten PM or CEO) on the user's
     * quotations this month. No review decision at all this month → no
     * data (null), not 0.
     */
    private function estimatorReturnedCount(User $user, CarbonInterface $from, CarbonInterface $to): ?float
    {
        $decisions = QuotationApproval::query()
            ->whereIn('quotation_id', Quotation::where('created_by', $user->id)->select('id'))
            ->whereIn('approver_role', [QuotationItemReview::STAGE_PM, QuotationItemReview::STAGE_CEO])
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->pluck('status');

        return $decisions->isEmpty() ? null : (float) $decisions->filter(fn (string $status) => $status === 'REJECTED')->count();
    }

    /**
     * The quotation versions the user approved at the PM stage that the
     * CEO decided this month; how many the CEO sent back — the review
     * "escaped" the PM. The CEO's return counts against the PM who
     * approved, not only the Estimator. Nothing decided → null.
     */
    private function pmReviewEscapedCount(User $user, CarbonInterface $from, CarbonInterface $to): ?float
    {
        $approved = QuotationApproval::query()
            ->where('approver_id', $user->id)
            ->where('approver_role', QuotationItemReview::STAGE_PM)
            ->where('status', 'APPROVED')
            ->get(['quotation_id', 'version']);

        if ($approved->isEmpty()) {
            return null;
        }

        $ceoDecisions = QuotationApproval::query()
            ->whereIn('quotation_id', $approved->pluck('quotation_id')->unique())
            ->where('approver_role', QuotationItemReview::STAGE_CEO)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->get(['quotation_id', 'version', 'status'])
            ->filter(fn (QuotationApproval $ceo) => $approved->contains(
                fn (QuotationApproval $pm) => $pm->quotation_id === $ceo->quotation_id && (int) $pm->version === (int) $ceo->version,
            ));

        return $ceoDecisions->isEmpty() ? null : (float) $ceoDecisions->where('status', 'REJECTED')->count();
    }

    // ── Lead (Marketing) ──────────────────────────────────────────────────

    private function leadNewCount(User $user, CarbonInterface $from, CarbonInterface $to): float
    {
        return (float) Lead::query()
            ->where('assigned_to', $user->id)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->count();
    }

    /**
     * Deals = the user's leads whose FIRST move to DEAL_DESAIN/CLOSING
     * happened this month; lost = moves to LOST this month. Rate =
     * deals ÷ (deals + lost). Leads count once each.
     */
    private function leadConversionRate(User $user, CarbonInterface $from, CarbonInterface $to): ?float
    {
        $leadIds = Lead::query()->where('assigned_to', $user->id)->select('id');

        $deals = PipelineLog::query()
            ->whereIn('lead_id', $leadIds)
            ->whereIn('to_status', [LeadStatus::DealDesain->value, LeadStatus::Closing->value])
            ->groupBy('lead_id')
            ->selectRaw('lead_id, MIN(created_at) as first_deal_at')
            ->get()
            ->filter(fn (PipelineLog $row) => Carbon::parse($row->first_deal_at)->gte($from) && Carbon::parse($row->first_deal_at)->lt($to))
            ->count();

        $lost = PipelineLog::query()
            ->whereIn('lead_id', $leadIds)
            ->where('to_status', LeadStatus::Lost->value)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->distinct()
            ->count('lead_id');

        return $deals + $lost > 0 ? $deals / ($deals + $lost) * 100 : null;
    }

    /**
     * Today's state: leads still in FOLLOW_UP with an open follow-up
     * (FU-n, Sprint 12) whose date (in the month) already passed.
     */
    private function leadOverdueFollowUps(User $user, CarbonInterface $from, CarbonInterface $asOf): float
    {
        return (float) Lead::query()
            ->where('assigned_to', $user->id)
            ->where('status', LeadStatus::FollowUp->value)
            ->whereHas('followUps', fn ($query) => $query->pending()
                ->where('scheduled_date', '>=', $from->toDateString())
                ->where('scheduled_date', '<', $asOf->toDateString()))
            ->count();
    }

    // ── Proyek (PM) ───────────────────────────────────────────────────────

    /**
     * Milestones of the PM's projects whose target date fell in the month
     * (and already passed). On time = COMPLETED with its QA approval —
     * the only completion moment recorded (QaFormService::review()) — no
     * later than the target date.
     */
    private function milestoneOnTimeRate(User $user, CarbonInterface $from, CarbonInterface $asOf): ?float
    {
        $milestones = Milestone::query()
            ->whereIn('project_id', Project::query()->where('pm_id', $user->id)->select('id'))
            ->where('target_date', '>=', $from->toDateString())
            ->where('target_date', '<', $asOf->toDateString())
            ->with('qaForm:id,milestone_id,status,reviewed_at')
            ->get(['id', 'project_id', 'target_date', 'status']);

        if ($milestones->isEmpty()) {
            return null;
        }

        $onTime = $milestones->filter(fn (Milestone $milestone) => $this->completedBy($milestone, $milestone->target_date->copy()->addDay()))->count();

        return $onTime / $milestones->count() * 100;
    }

    /** Whether a milestone was COMPLETED (QA-approved) before `$moment`. */
    private function completedBy(Milestone $milestone, CarbonInterface $moment): bool
    {
        if ($milestone->status !== MilestoneStatus::Completed) {
            return false;
        }

        $approvedAt = $milestone->qaForm?->status === QaStatus::Approved ? $milestone->qaForm->reviewed_at : null;

        // A COMPLETED milestone without a QA approval timestamp predates the QA flow — trust its status.
        return $approvedAt === null || $approvedAt->lt($moment);
    }

    /** First QA decision (audit trail, like DivisionDashboardService::qaStats()) of the PM's forms in the month. */
    private function qaFirstPassRate(User $user, CarbonInterface $from, CarbonInterface $to): ?float
    {
        $formIds = QaForm::query()
            ->whereIn('project_id', Project::query()->where('pm_id', $user->id)->select('id'))
            ->pluck('id');

        if ($formIds->isEmpty()) {
            return null;
        }

        $firstDecisions = AuditLog::query()
            ->where('model_type', 'QaForm')
            ->whereIn('action', ['qa.approved', 'qa.rejected'])
            ->whereIn('model_id', $formIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'model_id', 'action', 'created_at'])
            ->groupBy('model_id')
            ->map(fn (Collection $logs) => $logs->first())
            ->filter(fn (AuditLog $log) => $log->created_at->gte($from) && $log->created_at->lt($to));

        if ($firstDecisions->isEmpty()) {
            return null;
        }

        return $firstDecisions->filter(fn (AuditLog $log) => $log->action === 'qa.approved')->count() / $firstDecisions->count() * 100;
    }

    /**
     * The PM's projects running during the month that, at the month's end
     * (today for the running month), had a milestone past its target date
     * and not yet QA-approved. Null when the PM ran no project that month.
     */
    private function projectDelayCount(User $user, CarbonInterface $from, CarbonInterface $to, CarbonInterface $asOf): ?float
    {
        $projects = Project::query()
            ->where('pm_id', $user->id)
            ->where('status', '!=', ProjectStatus::Cancelled->value)
            ->where(fn ($query) => $query->whereNull('start_date')->orWhere('start_date', '<', $to->toDateString()))
            ->where(fn ($query) => $query->whereNull('end_date')->orWhere('end_date', '>=', $from->toDateString()))
            ->with(['milestones' => fn ($query) => $query
                ->where('target_date', '<', $asOf->toDateString())
                ->with('qaForm:id,milestone_id,status,reviewed_at')])
            ->get(['id', 'pm_id', 'status', 'start_date', 'end_date']);

        if ($projects->isEmpty()) {
            return null;
        }

        $endOfWindow = $asOf->copy()->startOfDay();

        return (float) $projects
            ->filter(fn (Project $project) => $project->milestones->contains(fn (Milestone $milestone) => ! $this->completedBy($milestone, $endOfWindow)))
            ->count();
    }

    // ── QA ────────────────────────────────────────────────────────────────

    private function qaFormsProcessed(User $user, CarbonInterface $from, CarbonInterface $to): float
    {
        return (float) AuditLog::query()
            ->where('model_type', 'QaForm')
            ->whereIn('action', ['qa.approved', 'qa.rejected'])
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->count();
    }

    // ── Finance ───────────────────────────────────────────────────────────

    /**
     * Team figure: termins due in the month (and already due) paid in full
     * no later than their scheduled date. Termin collection isn't
     * attributed to one person anywhere, so every Finance employee
     * holding this indicator gets the same value.
     */
    private function terminOnTimeRate(CarbonInterface $from, CarbonInterface $asOf): ?float
    {
        $termins = Termin::query()
            ->where('scheduled_date', '>=', $from->toDateString())
            ->where('scheduled_date', '<', $asOf->toDateString())
            ->get(['id', 'scheduled_date', 'paid_at']);

        if ($termins->isEmpty()) {
            return null;
        }

        $onTime = $termins
            ->filter(fn (Termin $termin) => $termin->paid_at !== null && $termin->paid_at->lt($termin->scheduled_date->copy()->addDay()))
            ->count();

        return $onTime / $termins->count() * 100;
    }
}
