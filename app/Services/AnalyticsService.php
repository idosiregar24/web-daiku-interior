<?php

namespace App\Services;

use App\Enums\LeadStatus;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\QuotationStatus;
use App\Enums\StockMovementType;
use App\Enums\TaskStatus;
use App\Models\FamilyGatheringFund;
use App\Models\Lead;
use App\Models\Material;
use App\Models\Milestone;
use App\Models\Penalty;
use App\Models\ProgressLog;
use App\Models\Project;
use App\Models\RevenueTarget;
use App\Models\StockMovement;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * PRD §4.10 CEO Executive Dashboard — one method per widget. Queries stay
 * engine-portable (no DATE_FORMAT/JSON operators; monthly/weekly
 * bucketing happens in PHP) so the SQLite test suite exercises the same
 * code MySQL runs — database-standards.md §1.
 */
class AnalyticsService
{
    public function __construct(private FinanceTransactionService $financeTransactionService) {}

    /**
     * "Pipeline Funnel: Chart corong jumlah lead per status". Stages are
     * cumulative ("reached at least this far"), which is what makes it a
     * funnel: a lead that went DEAL_DESAIN → LOST still counts at the
     * Deal Desain stage (read from pipeline_logs), not just by current
     * status. LOST is reported beside the funnel, not as a stage.
     */
    public function pipelineFunnel(): array
    {
        $total = Lead::count();
        $reachedDeal = Lead::query()
            ->where(fn ($query) => $query
                ->whereIn('status', [LeadStatus::DealDesain->value, LeadStatus::Closing->value])
                ->orWhereHas('pipelineLogs', fn ($q) => $q->where('to_status', LeadStatus::DealDesain->value)))
            ->count();
        $quotationApproved = Lead::whereHas('quotation', fn ($query) => $query->whereIn('status', [
            QuotationStatus::SentToClient->value,
            QuotationStatus::Approved->value,
        ]))->count();
        $closing = Lead::where('status', LeadStatus::Closing->value)->count();

        return [
            'stages' => [
                ['key' => 'lead', 'label' => 'Lead Masuk', 'count' => $total],
                ['key' => 'deal_desain', 'label' => 'Deal Desain', 'count' => $reachedDeal],
                ['key' => 'quotation', 'label' => 'Quotation Disetujui', 'count' => $quotationApproved],
                ['key' => 'closing', 'label' => 'Closing', 'count' => $closing],
            ],
            'lost' => Lead::where('status', LeadStatus::Lost->value)->count(),
            'conversionRate' => $total > 0 ? round($closing / $total * 100, 1) : 0.0,
        ];
    }

    /**
     * "Active Projects Overview: Card ringkasan proyek berjalan + progres".
     * Progress = PM's latest reported percentage (Progress Log); the
     * QA-approved milestone ratio is sent alongside as the objective check.
     */
    public function activeProjects(int $limit = 8): Collection
    {
        return Project::query()
            ->where('status', ProjectStatus::Active->value)
            ->with('pm:id,name')
            ->withCount([
                'milestones',
                'milestones as milestones_completed_count' => fn ($q) => $q->where('status', MilestoneStatus::Completed->value),
                'tasks as overdue_tasks_count' => fn ($q) => $q->overdue(),
            ])
            ->addSelect(['latest_progress' => ProgressLog::select('percentage')
                ->whereColumn('project_id', 'projects.id')
                ->latest('log_date')
                ->latest('id')
                ->limit(1)])
            ->orderBy('start_date')
            ->limit($limit)
            ->get(['id', 'name', 'pm_id', 'contract_value', 'start_date'])
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'pm' => $project->pm?->name,
                'contractValue' => (float) $project->contract_value,
                'progress' => (int) ($project->latest_progress ?? 0),
                'milestonesCompleted' => $project->milestones_completed_count,
                'milestonesTotal' => $project->milestones_count,
                'overdueTasks' => $project->overdue_tasks_count,
            ]);
    }

    /**
     * "Revenue vs Target: Grafik nilai kontrak vs target" — revenue is
     * contract value closed per month (a Project is created exactly when
     * a deal closes, LeadService::confirmDeal()), target is the CEO's
     * manual monthly input (RevenueTarget).
     */
    public function revenueVsTarget(int $months = 6): Collection
    {
        $from = now()->startOfMonth()->subMonths($months - 1);

        $revenue = Project::query()
            ->where('created_at', '>=', $from)
            ->get(['created_at', 'contract_value'])
            ->groupBy(fn (Project $project) => $project->created_at->format('Y-m'))
            ->map(fn (Collection $rows) => (float) $rows->sum('contract_value'));

        $targets = RevenueTarget::where('month', '>=', $from->format('Y-m'))->pluck('target_amount', 'month');

        return collect(range(0, $months - 1))
            ->map(fn (int $i) => $from->copy()->addMonths($i)->format('Y-m'))
            ->map(fn (string $month) => [
                'month' => $month,
                'label' => Carbon::createFromFormat('Y-m', $month)->translatedFormat('M Y'),
                'revenue' => $revenue->get($month, 0.0),
                'target' => isset($targets[$month]) ? (float) $targets[$month] : null,
            ]);
    }

    public function cashFlow(int $months = 6): Collection
    {
        return $this->financeTransactionService->monthlyCashFlow($months);
    }

    /**
     * "Team Performance: Performa PM (proyek selesai, ontime rate)".
     * On-time = a COMPLETED milestone whose QA approval (the moment it
     * truly completed, QaFormService::review()) landed on/before its
     * target_date — the only project-level deadline this schema records.
     */
    public function teamPerformance(): Collection
    {
        return User::role('PM')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function (User $pm) {
                $projects = Project::where('pm_id', $pm->id)->get(['id', 'status']);
                $projectIds = $projects->pluck('id');

                $completedMilestones = Milestone::query()
                    ->whereIn('project_id', $projectIds)
                    ->where('status', MilestoneStatus::Completed->value)
                    ->with('qaForm:id,milestone_id,reviewed_at')
                    ->get(['id', 'target_date']);

                $onTime = $completedMilestones->filter(fn (Milestone $milestone) => $milestone->qaForm?->reviewed_at
                    && $milestone->qaForm->reviewed_at->startOfDay()->lte($milestone->target_date))->count();

                return [
                    'id' => $pm->id,
                    'name' => $pm->name,
                    'activeProjects' => $projects->where('status', ProjectStatus::Active)->count(),
                    'completedProjects' => $projects->where('status', ProjectStatus::Completed)->count(),
                    'milestonesCompleted' => $completedMilestones->count(),
                    'onTimeRate' => $completedMilestones->isNotEmpty()
                        ? round($onTime / $completedMilestones->count() * 100)
                        : null,
                    'overdueTasks' => Task::whereIn('project_id', $projectIds)->overdue()->count(),
                ];
            });
    }

    /** "Penalty Summary: Total penalti tukang + dana family gathering". */
    public function penaltySummary(): array
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $thisMonth = Penalty::whereDate('date_occurred', '>=', $monthStart);

        $income = (float) FamilyGatheringFund::where('type', 'INCOME')->sum('amount');
        $expense = (float) FamilyGatheringFund::where('type', 'EXPENSE')->sum('amount');

        return [
            'monthTotal' => (float) (clone $thisMonth)->sum('amount'),
            'monthCount' => (clone $thisMonth)->count(),
            'allTimeTotal' => (float) Penalty::sum('amount'),
            'fundBalance' => $income - $expense,
            'topStaff' => (clone $thisMonth)
                ->with('staff:id,name')
                ->get(['staff_id', 'amount'])
                ->groupBy('staff_id')
                ->map(fn (Collection $rows) => [
                    'name' => $rows->first()->staff?->name ?? '—',
                    'count' => $rows->count(),
                    'total' => (float) $rows->sum('amount'),
                ])
                ->sortByDesc('total')
                ->take(5)
                ->values(),
        ];
    }

    /**
     * "Material Margin Report: Total margin profit logistik". Realized =
     * units issued to projects × (sell − cost) at current master prices
     * (the ledger doesn't snapshot prices); potential = what the stock
     * on hand would earn if used.
     */
    public function materialMargin(): array
    {
        $realizedByMaterial = StockMovement::query()
            ->join('materials', 'materials.id', '=', 'stock_movements.material_id')
            ->where('stock_movements.type', StockMovementType::Out->value)
            ->groupBy('materials.id', 'materials.name')
            ->selectRaw('materials.name as name')
            ->selectRaw('SUM(stock_movements.qty) as qty_used')
            ->selectRaw('SUM(stock_movements.qty * (materials.sell_price - materials.cost_price)) as margin')
            ->orderByDesc('margin')
            ->get();

        return [
            'realized' => (float) $realizedByMaterial->sum('margin'),
            'potential' => (float) Material::query()->selectRaw('COALESCE(SUM(stock * (sell_price - cost_price)), 0) as m')->value('m'),
            'lowStockCount' => Material::lowStock()->count(),
            'topMaterials' => $realizedByMaterial->take(5)->map(fn ($row) => [
                'name' => $row->name,
                'qtyUsed' => (int) $row->qty_used,
                'margin' => (float) $row->margin,
            ])->values(),
        ];
    }

    /**
     * "Overdue Tasks Heatmap: Visual task yang terlambat per proyek" —
     * rows are active projects with overdue work, columns are the week
     * each task fell due (last `$weeks` weeks; anything older folds into
     * the first column so no overdue task silently drops out).
     */
    public function overdueHeatmap(int $weeks = 8): array
    {
        $currentWeek = now()->startOfWeek(Carbon::MONDAY);
        $firstWeek = $currentWeek->copy()->subWeeks($weeks - 1);

        $columns = collect(range(0, $weeks - 1))->map(function (int $i) use ($firstWeek) {
            $start = $firstWeek->copy()->addWeeks($i);

            return [
                'key' => $start->toDateString(),
                'label' => $i === 0 ? '≤ '.$start->translatedFormat('d M') : $start->translatedFormat('d M'),
            ];
        });

        $tasks = Task::query()
            ->overdue()
            ->whereHas('project', fn ($q) => $q->where('status', ProjectStatus::Active->value))
            ->with('project:id,name')
            ->get(['id', 'project_id', 'due_date']);

        $rows = $tasks->groupBy('project_id')->map(function (Collection $projectTasks) use ($firstWeek, $weeks) {
            $cells = array_fill(0, $weeks, 0);

            foreach ($projectTasks as $task) {
                $index = max(0, (int) floor($firstWeek->diffInDays($task->due_date->copy()->startOfWeek(Carbon::MONDAY), false) / 7));
                $cells[min($index, $weeks - 1)]++;
            }

            return [
                'projectId' => $projectTasks->first()->project_id,
                'name' => $projectTasks->first()->project->name,
                'cells' => $cells,
                'total' => $projectTasks->count(),
            ];
        })->sortByDesc('total')->values();

        return [
            'columns' => $columns,
            'rows' => $rows,
            'max' => (int) $rows->flatMap(fn (array $row) => $row['cells'])->max(),
        ];
    }

    /** Task counts by status across active projects — context for the heatmap. */
    public function taskStatusBreakdown(): array
    {
        $counts = Task::query()
            ->whereHas('project', fn ($q) => $q->where('status', ProjectStatus::Active->value))
            ->get(['status'])
            ->countBy(fn (Task $task) => $task->status->value);

        return collect(TaskStatus::cases())->mapWithKeys(fn (TaskStatus $status) => [$status->value => $counts->get($status->value, 0)])->all();
    }
}
