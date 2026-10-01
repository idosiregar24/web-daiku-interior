<?php

namespace App\Http\Controllers\QA;

use App\Http\Controllers\Controller;
use App\Services\DivisionDashboardService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard QA — the QA division's "Analytics – Per Divisi" view (PRD
 * §7.1 `P`, §4.6) and landing page (RoleRedirectService): review queue,
 * decisions this month, repeat rejections. CEO + QA only. Project and
 * milestone level only — PRD §4.6 "QA tidak bisa melihat detail task
 * tukang", so no task data is ever part of these props.
 */
class QaDashboardController extends Controller
{
    public function index(DivisionDashboardService $dashboards): Response
    {
        return Inertia::render('QA/Dashboard', [
            'stats' => $dashboards->qaStats(),
            'pending' => $dashboards->qaPendingForms(),
            'repeatRejections' => $dashboards->qaRepeatRejections(),
        ]);
    }
}
