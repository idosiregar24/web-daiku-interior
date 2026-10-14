<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\OpenProjectRequest;
use App\Models\ProjectOpening;
use App\Services\ProjectService;
use Illuminate\Http\RedirectResponse;

/**
 * "Buka Proyek" — the pop-up on every page, the "Menunggu Dibuka" list on
 * Proyek and "Proyek siap dibuka" in Perlu Tindakan post here. Sprint 23
 * K13: route `role:PM` + ProjectOpeningPolicy::open() in OpenProjectRequest
 * (the PM who approved the RAB Proyek; any PM when an Asisten PM did).
 */
class ProjectOpeningController extends Controller
{
    public function open(OpenProjectRequest $request, ProjectOpening $opening, ProjectService $service): RedirectResponse
    {
        $project = $service->openFromQuotation($opening, $request->validated(), $request->user());

        return redirect()->route('projects.show', $project)->with('success', "Proyek \"{$project->name}\" dibuka — termin dibuat dari skema pembayaran.");
    }
}
