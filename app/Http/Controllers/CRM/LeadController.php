<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Http\Requests\CRM\ConfirmLeadDealRequest;
use App\Http\Requests\CRM\StoreLeadRequest;
use App\Http\Requests\CRM\UpdateLeadRequest;
use App\Http\Requests\CRM\UpdateLeadStatusRequest;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\User;
use App\Services\LeadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeadController extends Controller
{
    public function index(Request $request): Response
    {
        $leads = Lead::query()
            ->with(['assignee:id,name', 'creator:id,name'])
            ->byStatus($request->string('status')->value() ?: null)
            ->byPriority($request->string('priority')->value() ?: null)
            ->when($request->filled('search'), fn ($query) => $query->where(
                'client_name',
                'like',
                '%'.$request->string('search').'%',
            ))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('CRM/Index', [
            'leads' => $leads,
            'filters' => $request->only(['status', 'priority', 'search']),
            'marketers' => User::role('MARKETING')->orderBy('name')->get(['id', 'name']),
            'projectManagers' => User::role('PM')->orderBy('name')->get(['id', 'name']),
            // Sumber Lead — Master Data list (SuperAdmin-editable, see
            // MasterData\LeadSourceController). `leads.source` itself stays
            // a free string column (see lead_sources migration docblock),
            // so the form picks a source's `name`, not its `id`.
            'leadSources' => LeadSource::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreLeadRequest $request, LeadService $service): RedirectResponse
    {
        $service->create($request->validated(), $request->user());

        return redirect()->route('crm.leads.index')->with('success', 'Lead berhasil ditambahkan.');
    }

    public function update(UpdateLeadRequest $request, Lead $lead, LeadService $service): RedirectResponse
    {
        $service->update($lead, $request->validated());

        return back()->with('success', 'Lead berhasil diperbarui.');
    }

    public function updateStatus(UpdateLeadStatusRequest $request, Lead $lead, LeadService $service): RedirectResponse
    {
        $service->changeStatus($lead, $request->validated(), $request->user());

        return back()->with('success', 'Status lead diperbarui.');
    }

    /**
     * PRD §4.1/§4.4 — Marketing confirms a deal; this closes the lead's
     * pipeline (CLOSING) and creates the execution Project in one step.
     * See LeadService::confirmDeal().
     */
    public function confirmDeal(ConfirmLeadDealRequest $request, Lead $lead, LeadService $service): RedirectResponse
    {
        $service->confirmDeal($lead, $request->validated(), $request->user());

        return redirect()->route('crm.leads.index')->with('success', 'Deal dikonfirmasi, proyek baru telah dibuat.');
    }
}
