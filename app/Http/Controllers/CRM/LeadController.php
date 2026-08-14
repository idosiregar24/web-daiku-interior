<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Http\Requests\CRM\StoreLeadRequest;
use App\Models\Lead;
use App\Services\LeadService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeadController extends Controller
{
    /**
     * PRD §4.1 + .claude/plan/sprint-01.md Week 2. `edit`/`update`/
     * `updateStatus` land in Sprint 2 (create/edit form modal, pipeline
     * status update) — see .claude/plan/sprint-02.md — not here yet.
     */
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
        ]);
    }

    public function store(StoreLeadRequest $request, LeadService $service)
    {
        $service->create($request->validated(), $request->user());

        return redirect()->route('crm.leads.index')->with('success', 'Lead berhasil ditambahkan.');
    }
}
