<?php

namespace App\Http\Controllers\Design;

use App\Http\Controllers\Controller;
use App\Services\DivisionDashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * KPI Desain — PRD §4.2 "KPI per PIC" + "Tracking Omset Desain", the
 * Design division's "Analytics – Per Divisi" view (PRD §7.1 `P`) and a
 * DESIGNER's landing page (RoleRedirectService). CEO + DESIGNER only;
 * every query lives in DivisionDashboardService.
 */
class DesignDashboardController extends Controller
{
    public function index(Request $request, DivisionDashboardService $dashboards): Response
    {
        // Read-only month filter: parsed strictly, bad input → default range (see monthRange()).
        $range = $dashboards->monthRange($request->query('from'), $request->query('to'));
        $user = $request->user();

        return Inertia::render('Design/Dashboard', [
            'kpis' => $dashboards->designKpis(),
            'revenue' => $dashboards->designRevenue($range['from'], $range['to']),
            'range' => ['from' => $range['from']->format('Y-m'), 'to' => $range['to']->format('Y-m')],
            'monthOptions' => $dashboards->monthOptions(),
            // "Desain Saya" — only a designer has designs of their own.
            'myDesigns' => $user->hasRole('DESIGNER') ? $dashboards->myDesigns($user) : null,
        ]);
    }
}
