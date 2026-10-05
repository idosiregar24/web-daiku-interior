<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\OpenProjectRequest;
use App\Models\ProjectOpening;
use App\Services\ProjectService;
use Illuminate\Http\RedirectResponse;

/**
 * Sprint 12 decision #19 — "Buka Proyek" (CEO): the pop-up on every page
 * and the "Menunggu Dibuka" list on Proyek post here.
 */
class ProjectOpeningController extends Controller
{
    public function open(OpenProjectRequest $request, ProjectOpening $opening, ProjectService $service): RedirectResponse
    {
        $project = $service->openFromQuotation($opening, $request->validated(), $request->user());

        return redirect()->route('projects.show', $project)->with('success', "Proyek \"{$project->name}\" dibuka — termin dibuat dari skema pembayaran.");
    }
}
