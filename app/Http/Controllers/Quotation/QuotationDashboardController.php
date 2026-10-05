<?php

namespace App\Http\Controllers\Quotation;

use App\Enums\QuotationStatus;
use App\Http\Controllers\Controller;
use App\Services\DivisionDashboardService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard Quotation — the Estimator's "Analytics – Per Divisi" view
 * (PRD §7.1 `P`, §4.3) and landing page (RoleRedirectService): the
 * approval pipeline's work queues, RAB value per month and turnaround.
 * CEO + ESTIMATOR only; queries in DivisionDashboardService.
 */
class QuotationDashboardController extends Controller
{
    public function index(DivisionDashboardService $dashboards): Response
    {
        return Inertia::render('Quotation/Dashboard', [
            'statusCounts' => $dashboards->quotationStatusCounts(),
            'todo' => $dashboards->quotationQueue(QuotationStatus::Draft),
            // Sprint 12 #7: PM / Asisten PM review first, then the CEO (RAB Proyek).
            'waitingPm' => $dashboards->quotationQueue(QuotationStatus::Submitted),
            'waitingCeo' => $dashboards->quotationQueue(QuotationStatus::WaitingCeo),
            'readyToSend' => $dashboards->quotationQueue(QuotationStatus::ApprovedInternal),
            'monthly' => $dashboards->quotationMonthlyValue(),
            'turnaround' => $dashboards->quotationTurnaround(),
        ]);
    }
}
