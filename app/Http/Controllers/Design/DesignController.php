<?php

namespace App\Http\Controllers\Design;

use App\Http\Controllers\Controller;
use App\Http\Requests\Design\StoreDesignRequest;
use App\Http\Requests\Design\UpdateDesignRequest;
use App\Models\Design;
use App\Models\Lead;
use App\Models\User;
use App\Services\DesignService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PRD §4.2. Design *creation* is reached from the CRM Lead index (a
 * DEAL_DESAIN lead gets a "Buka Desain" action) — `index()` here is the
 * general listing, wired to the "Desain" sidebar nav entry (CLAUDE.md
 * golden rule #8) as of Sprint 2 Week 4.
 */
class DesignController extends Controller
{
    public function index(Request $request): Response
    {
        $designs = Design::query()
            ->with(['lead:id,client_name', 'pic:id,name'])
            ->byStatus($request->string('status')->value() ?: null)
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Design/Index', [
            'designs' => $designs,
            'filters' => $request->only(['status']),
        ]);
    }

    public function store(StoreDesignRequest $request, Lead $lead, DesignService $service): RedirectResponse
    {
        $design = $service->create($lead, $request->validated());

        return redirect()->route('design.show', $design)->with('success', 'Proyek desain berhasil dibuka.');
    }

    public function show(Request $request, Design $design): Response
    {
        $design->load(['lead:id,client_name', 'pic:id,name']);

        return Inertia::render('Design/Show', [
            'design' => $design,
            'canManage' => $request->user()->hasAnyRole(['DESIGNER', 'SUPERADMIN']),
            'canClientAcc' => $request->user()->hasAnyRole(['MARKETING', 'DESIGNER', 'SUPERADMIN']),
            'designers' => User::role('DESIGNER')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateDesignRequest $request, Design $design, DesignService $service): RedirectResponse
    {
        $service->update($design, $request->validated());

        return back()->with('success', 'Brief desain berhasil diperbarui.');
    }

    public function clientAcc(Request $request, Design $design, DesignService $service): RedirectResponse
    {
        $design = $service->clientAcc($design, $request->user());

        // Quotation is a sibling of Design via lead_id, not a direct
        // relation on Design — go through the Lead (Lead::quotation()).
        $quotation = $design->lead->quotation;

        return redirect()->route('quotations.show', $quotation)
            ->with('success', 'Desain di-ACC klien. Quotation baru telah dibuka.');
    }
}
