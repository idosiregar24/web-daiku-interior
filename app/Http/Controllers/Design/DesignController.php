<?php

namespace App\Http\Controllers\Design;

use App\Http\Controllers\Controller;
use App\Http\Requests\Design\StoreDesignRequest;
use App\Models\Lead;
use App\Services\DesignService;
use Illuminate\Http\RedirectResponse;

/**
 * PRD §4.2. Only `store` this sprint (.claude/plan/sprint-02.md Week 3) —
 * the actual brief-form/status-badge UI is Week 4's task, so there's no
 * page to render yet. Design creation is reached from the CRM Lead index
 * (a DEAL_DESAIN lead gets a "Buka Desain" action), not its own nav entry.
 */
class DesignController extends Controller
{
    public function store(StoreDesignRequest $request, Lead $lead, DesignService $service): RedirectResponse
    {
        $service->create($lead, $request->validated());

        return back()->with('success', 'Proyek desain berhasil dibuka.');
    }
}
