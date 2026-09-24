<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\StoreRevenueTargetRequest;
use App\Models\RevenueTarget;
use App\Services\AnalyticsService;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PRD §4.10 CEO Executive Dashboard — §7.1 "Analytics – Executive": CEO
 * only, FULL. Division roles get their own partial dashboards instead
 * (crm.dashboard, finance.dashboard, the Logistics material summary),
 * never this company-wide query set (security-standards.md §4).
 */
class AnalyticsController extends Controller
{
    public function index(AnalyticsService $analytics): Response
    {
        return Inertia::render('Analytics/Index', [
            'funnel' => $analytics->pipelineFunnel(),
            'activeProjects' => $analytics->activeProjects(),
            'revenue' => $analytics->revenueVsTarget(),
            'cashFlow' => $analytics->cashFlow(),
            'teamPerformance' => $analytics->teamPerformance(),
            'penalties' => $analytics->penaltySummary(),
            'materialMargin' => $analytics->materialMargin(),
            'overdueHeatmap' => $analytics->overdueHeatmap(),
            'taskStatus' => $analytics->taskStatusBreakdown(),
            'generatedAt' => now()->toIso8601String(),
        ]);
    }

    /** CSV Sprint 6 "input target manual per bulan" — one row per month, re-saving overwrites. */
    public function storeTarget(StoreRevenueTargetRequest $request, AuditLogService $audit): RedirectResponse
    {
        $previous = RevenueTarget::where('month', $request->validated('month'))->value('target_amount');

        $target = RevenueTarget::updateOrCreate(
            ['month' => $request->validated('month')],
            ['target_amount' => $request->validated('target_amount'), 'set_by' => $request->user()->id],
        );

        $audit->record(
            'analytics.target_set',
            $target,
            $previous === null ? null : ['target_amount' => $previous],
            ['month' => $target->month, 'target_amount' => $target->target_amount],
        );

        return back()->with('success', 'Target pendapatan disimpan.');
    }
}
