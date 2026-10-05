<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\RequestAddendumRequest;
use App\Models\Project;
use App\Services\QuotationService;
use Illuminate\Http\RedirectResponse;

/**
 * Sprint 12 decision #29 — "Minta RAB Tambahan" from the project page.
 * The addendum then runs the normal RAB flow on the Quotation pages.
 */
class ProjectAddendumController extends Controller
{
    public function store(RequestAddendumRequest $request, Project $project, QuotationService $service): RedirectResponse
    {
        $quotation = $service->requestAddendum($project, $request->validated('note'), $request->user());

        return redirect()->route('quotations.show', $quotation)->with('success', 'RAB Tambahan diminta — Estimator diberi tahu.');
    }
}
