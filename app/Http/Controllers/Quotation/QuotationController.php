<?php

namespace App\Http\Controllers\Quotation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Quotation\UpdateQuotationItemsRequest;
use App\Models\Quotation;
use App\Services\QuotationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PRD §4.3. RAB builder only this sprint (.claude/plan/sprint-02.md Week
 * 4) — see QuotationStatus/QuotationService docblocks for what's deferred
 * to Week 5 (CEO/PM approval actions, PDF export, SENT_TO_CLIENT+).
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
        $quotation->load(['lead:id,client_name', 'items']);

        return Inertia::render('Quotation/Show', [
            'quotation' => $quotation,
            'canManage' => $request->user()->hasAnyRole(['ESTIMATOR', 'SUPERADMIN']),
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
}
