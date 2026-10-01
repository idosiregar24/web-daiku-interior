<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Services\DivisionDashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Monitor Proyek — PRD §4.4 "Overdue Monitor: Dashboard khusus PM untuk
 * memantau task yang melewati deadline", the PM's landing page
 * (RoleRedirectService). CEO + PM only. A PM sees their own projects
 * (`pm_id`); CEO sees every PM's and may narrow to one — scoping lives in
 * DivisionDashboardService::monitoredProjects(), so a `pm_id` sent by a PM
 * is ignored.
 */
class ProjectDashboardController extends Controller
{
    public function index(Request $request, DivisionDashboardService $dashboards): Response
    {
        $user = $request->user();
        $canFilterPm = $dashboards->seesAllProjects($user);
        $pmId = $canFilterPm ? ($request->integer('pm_id') ?: null) : null;

        return Inertia::render('Projects/Dashboard', [
            ...$dashboards->projectMonitor($user, $pmId),
            'filters' => ['pm_id' => $pmId],
            'pmOptions' => $canFilterPm ? $dashboards->projectManagers() : [],
        ]);
    }
}
