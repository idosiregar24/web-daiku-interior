<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\DisciplineService;
use App\Services\KpiService;
use App\Services\PerformanceReviewService;
use App\Services\SalaryChangeService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SDM dashboard (Sprint 10 SDM-6) — HR's landing page (RoleRedirectService),
 * also read by the CEO. Each panel comes from its part's service
 * (`dashboardSummary()`), so the figures match the module pages.
 */
class HrDashboardController extends Controller
{
    public function index(
        DisciplineService $discipline,
        SalaryChangeService $salary,
        KpiService $kpi,
        PerformanceReviewService $reviews,
    ): Response {
        $employees = Employee::query()->hrEligible();

        return Inertia::render('HR/Dashboard', [
            'headcount' => [
                'active' => (clone $employees)->where('is_active', true)->count(),
                'inactive' => (clone $employees)->where('is_active', false)->count(),
                'withoutAccount' => (clone $employees)->where('is_active', true)->whereNull('user_id')->count(),
                'joinedThisYear' => (clone $employees)->whereYear('join_date', now()->year)->count(),
            ],
            'discipline' => $discipline->dashboardSummary(),
            'salary' => $salary->dashboardSummary(),
            'kpi' => $kpi->dashboardSummary(),
            'reviews' => $reviews->dashboardSummary(),
        ]);
    }
}
