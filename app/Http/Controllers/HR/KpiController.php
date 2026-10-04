<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Http\Requests\HR\OpenKpiPeriodRequest;
use App\Http\Requests\HR\SaveKpiTemplateRequest;
use App\Http\Requests\HR\UpdateKpiScoreRequest;
use App\Models\Division;
use App\Models\KpiPeriod;
use App\Models\KpiScore;
use App\Models\Position;
use App\Services\Kpi\KpiMetricRegistry;
use App\Services\KpiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SDM (Sprint 10, §3.3) — monthly KPI. The whole `hr.` group is CEO|HR
 * (`module:hr`); every write below sits behind `role:HR` in
 * routes/hr/kpi.php (§4: CEO reads, HR manages). No destroy route:
 * templates are deactivated, periods are closed, scores are locked.
 */
class KpiController extends Controller
{
    public function index(Request $request, KpiService $service): Response
    {
        $periods = KpiPeriod::query()->with('closer:id,name')->orderByDesc('period')->get();
        $selected = $periods->firstWhere('period', $request->string('period')->value()) ?? $periods->first();
        $divisionId = $request->integer('division') ?: null;
        $positionId = $request->integer('position') ?: null;

        return Inertia::render('HR/Kpi/Index', [
            'periods' => $periods->map(fn (KpiPeriod $period) => [
                'id' => $period->id,
                'period' => $period->period,
                'label' => $service->monthLabel($period->period),
                'status' => $period->status->value,
                'closedAt' => $period->closed_at?->toIso8601String(),
                'closerName' => $period->closer?->name,
            ])->values(),
            'selected' => $selected ? [
                'id' => $selected->id,
                'period' => $selected->period,
                'label' => $service->monthLabel($selected->period),
                'status' => $selected->status->value,
                'closedAt' => $selected->closed_at?->toIso8601String(),
                'closerName' => $selected->closer?->name,
                'progress' => $service->openProgress($selected),
            ] : null,
            'board' => $selected ? $service->periodBoard($selected, $divisionId, $positionId) : [],
            'trend' => $service->companyTrend(),
            'filters' => ['division' => $divisionId, 'position' => $positionId],
            'structure' => $this->structure(),
            'currentMonth' => now()->format('Y-m'),
            'canManage' => $request->user()->hasAnyRole(['HR', 'SUPERADMIN']),
        ]);
    }

    public function templates(Request $request, KpiService $service, KpiMetricRegistry $metrics): Response
    {
        return Inertia::render('HR/Kpi/Templates', [
            'divisions' => $service->templateBoard(),
            'metrics' => $metrics->options(),
            'canManage' => $request->user()->hasAnyRole(['HR', 'SUPERADMIN']),
        ]);
    }

    public function saveTemplate(SaveKpiTemplateRequest $request, Position $position, KpiService $service): RedirectResponse
    {
        $service->saveTemplate($position, $request->validated('indicators'), $request->user());

        return back()->with('success', "Template KPI {$position->name} disimpan.");
    }

    public function toggleTemplate(Request $request, Position $position, KpiService $service): RedirectResponse
    {
        $template = $service->toggleTemplate($position, $request->user());

        return back()->with('success', $template->is_active
            ? "Template KPI {$position->name} diaktifkan."
            : "Template KPI {$position->name} dinonaktifkan.");
    }

    public function storePeriod(OpenKpiPeriodRequest $request, KpiService $service): RedirectResponse
    {
        $period = $service->openPeriod($request->validated('period'), $request->user());

        return redirect()->route('hr.kpi.index', ['period' => $period->period])
            ->with('success', 'Periode KPI '.$service->monthLabel($period->period).' dibuka.');
    }

    public function compute(Request $request, KpiPeriod $kpiPeriod, KpiService $service): RedirectResponse
    {
        $summary = $service->compute($kpiPeriod, $request->user());

        return back()->with('success', "KPI {$service->monthLabel($kpiPeriod->period)} dihitung untuk {$summary['employees']} karyawan."
            .($summary['manualMissing'] > 0 ? " {$summary['manualMissing']} nilai manual belum diisi." : ''));
    }

    public function close(Request $request, KpiPeriod $kpiPeriod, KpiService $service): RedirectResponse
    {
        $service->close($kpiPeriod, $request->user());

        return back()->with('success', "Periode KPI {$service->monthLabel($kpiPeriod->period)} ditutup dan dikunci.");
    }

    public function updateScore(UpdateKpiScoreRequest $request, KpiScore $kpiScore, KpiService $service): RedirectResponse
    {
        $actual = $request->validated('actual');
        $service->updateManualScore($kpiScore, $actual === null ? null : (float) $actual, $request->user());

        return back()->with('success', "Nilai {$kpiScore->indicator_name} disimpan.");
    }

    /** Divisions with their positions for the StructureFilter. */
    private function structure()
    {
        return Division::query()
            ->ordered()
            ->with(['positions' => fn ($query) => $query->ordered()->select(['id', 'division_id', 'name', 'is_active'])])
            ->get(['id', 'name', 'is_active']);
    }
}
