<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\DisciplineService;
use App\Services\KpiService;
use App\Services\PerformanceReviewService;
use App\Services\SalaryChangeService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SDM "Milik Saya" (Sprint 10, decision #6) — the signed-in employee's own
 * KPI, reviews, warnings and salary history. The `employee.self` route
 * middleware resolves the employee from the session user, so this page can
 * never be pointed at someone else; KPI shows CLOSED months only and
 * reviews only once APPROVED. Field staff never get here (decision #11).
 */
class MyHrController extends Controller
{
    public function index(
        Request $request,
        DisciplineService $discipline,
        SalaryChangeService $salary,
        KpiService $kpi,
        PerformanceReviewService $reviews,
    ): Response {
        /** @var Employee $employee */
        $employee = $request->attributes->get('employee');
        $employee->load(['position:id,name,division_id', 'position.division:id,name']);

        return Inertia::render('HR/My/Index', [
            'employee' => $employee->only(['id', 'name', 'is_active', 'join_date', 'position']),
            'discipline' => $discipline->forEmployee($employee),
            // Self view: approved changes only — never a pending/rejected request.
            'salary' => $salary->forEmployee($employee, true),
            'kpi' => $kpi->forEmployee($employee, true),
            'reviews' => $reviews->forEmployee($employee, true),
        ]);
    }
}
