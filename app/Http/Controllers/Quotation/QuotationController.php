<?php

namespace App\Http\Controllers\Quotation;

use App\Enums\QuotationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Quotation\ClientRejectQuotationRequest;
use App\Http\Requests\Quotation\QuotationDecisionRequest;
use App\Http\Requests\Quotation\UpdateQuotationItemsRequest;
use App\Models\Quotation;
use App\Models\SiteSetting;
use App\Models\Unit;
use App\Services\QuotationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PRD §4.3. RAB builder (Sprint 2 Week 4) + CEO→PM dual approval + PDF
 * export (Sprint 3 Week 5) + the client's rejection, revision history and
 * validity period (Sprint 9) — see QuotationService's docblock for the
 * state machine. The client's acceptance is LeadController::confirmDeal().
 */
class QuotationController extends Controller
{
    public function index(Request $request): Response
    {
        $quotations = Quotation::query()
            ->with('lead:id,client_name')
            ->byStatus($request->string('status')->value() ?: null)
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Quotation/Index', [
            'quotations' => $quotations,
            'filters' => $request->only(['status']),
        ]);
    }

    public function show(Request $request, Quotation $quotation): Response
    {
        $quotation->load([
            'lead:id,client_name',
            'items',
            'approvals.approver:id,name',
            'revisions.closer:id,name',
        ]);

        $user = $request->user();

        return Inertia::render('Quotation/Show', [
            'quotation' => $quotation,
            'canManage' => $user->hasAnyRole(['ESTIMATOR', 'SUPERADMIN']),
            'canCeoDecide' => $user->hasAnyRole(['CEO', 'SUPERADMIN']),
            'canPmDecide' => $user->hasAnyRole(['PM', 'SUPERADMIN']),
            // Recording the client's decision — same roles as the
            // `quotations.clientReject` / `crm.leads.confirmDeal` routes.
            'canClientDecide' => $user->hasAnyRole(['CEO', 'MARKETING', 'SUPERADMIN']),
            'validityDays' => QuotationService::VALIDITY_DAYS,
            // Master Satuan dropdown for the RAB builder (only editable while DRAFT).
            'units' => $quotation->status === QuotationStatus::Draft ? Unit::options() : [],
        ]);
    }

    public function updateItems(UpdateQuotationItemsRequest $request, Quotation $quotation, QuotationService $service): RedirectResponse
    {
        $service->replaceItems($quotation, $request->validated('items'));

        return back()->with('success', 'Item RAB berhasil disimpan.');
    }

    public function submit(Quotation $quotation, QuotationService $service): RedirectResponse
    {
        $service->submit($quotation);

        return back()->with('success', 'Quotation berhasil disubmit untuk review.');
    }

    public function ceoDecision(QuotationDecisionRequest $request, Quotation $quotation, QuotationService $service): RedirectResponse
    {
        $service->ceoDecision($quotation, $request->validated('decision'), $request->user(), $request->validated('note'));

        return back()->with('success', 'Keputusan CEO atas quotation tersimpan.');
    }

    public function pmDecision(QuotationDecisionRequest $request, Quotation $quotation, QuotationService $service): RedirectResponse
    {
        $service->pmDecision($quotation, $request->validated('decision'), $request->user(), $request->validated('note'));

        return back()->with('success', 'Keputusan PM atas quotation tersimpan.');
    }

    /**
     * PRD §6.2 "SENT TO CLIENT → REJECTED (klien) → DRAFT (revisi)" — see
     * QuotationService::clientReject().
     */
    public function clientReject(ClientRejectQuotationRequest $request, Quotation $quotation, QuotationService $service): RedirectResponse
    {
        $quotation = $service->clientReject($quotation, $request->user(), $request->validated('note'));

        return back()->with('success', "Penolakan klien dicatat — quotation kembali ke DRAFT sebagai versi {$quotation->version} untuk direvisi.");
    }

    public function exportPdf(Quotation $quotation): HttpResponse
    {
        $quotation->load(['lead:id,client_name', 'items']);

        $pdf = Pdf::loadView('pdf.quotation', [
            'quotation' => $quotation,
            'siteSettings' => SiteSetting::current(),
            'validityDays' => QuotationService::VALIDITY_DAYS,
        ]);

        return $pdf->stream("penawaran-{$quotation->lead->client_name}-v{$quotation->version}.pdf");
    }
}
