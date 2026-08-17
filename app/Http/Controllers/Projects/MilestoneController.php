<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreMilestoneRequest;
use App\Http\Requests\Projects\UpdateMilestoneRequest;
use App\Models\Milestone;
use App\Models\Project;
use App\Services\MilestoneService;
use Illuminate\Http\RedirectResponse;

class MilestoneController extends Controller
{
    public function store(StoreMilestoneRequest $request, Project $project, MilestoneService $service): RedirectResponse
    {
        $service->create($project, $request->validated());

        return back()->with('success', 'Milestone berhasil ditambahkan.');
    }

    public function update(UpdateMilestoneRequest $request, Milestone $milestone, MilestoneService $service): RedirectResponse
    {
        $service->update($milestone, $request->validated());

        return back()->with('success', 'Milestone berhasil diperbarui.');
    }

    public function destroy(Milestone $milestone): RedirectResponse
    {
        $milestone->delete();

        return back()->with('success', 'Milestone berhasil dihapus.');
    }

    /**
     * PRD §4.6/§6.3 — PM "menandai milestone selesai", membuka QA review
     * (bukan langsung COMPLETED). See MilestoneService::markDone()'s
     * docblock.
     */
    public function markDone(Milestone $milestone, MilestoneService $service): RedirectResponse
    {
        $service->markDone($milestone);

        return back()->with('success', 'Milestone diajukan untuk review QA.');
    }
}
