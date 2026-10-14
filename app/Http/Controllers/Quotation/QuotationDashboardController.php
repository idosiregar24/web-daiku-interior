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
            'waitingPm' => $dashboards->quotationQueue(QuotationStatus::Submitted),
            // Sprint 23 K8/K10 — the PM's approval goes straight to Marketing (no CEO stage).
            'readyToSend' => $dashboards->quotationQueue(QuotationStatus::ReadyToSend),
            // Sprint 23 K11 — the client said "Tidak Setuju"; Marketing forwards it back.
            'clientRejected' => $dashboards->quotationQueue(QuotationStatus::ClientRejected),
            'monthly' => $dashboards->quotationMonthlyValue(),
            'turnaround' => $dashboards->quotationTurnaround(),
        ]);
    }
}
