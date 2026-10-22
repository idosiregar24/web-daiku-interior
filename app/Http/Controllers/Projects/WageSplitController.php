<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\SaveCareerLevelsRequest;
use App\Http\Requests\Projects\SaveProjectWageSplitRequest;
use App\Http\Requests\Projects\SaveWorkTeamRequest;
use App\Models\Project;
use App\Models\WorkTeam;
use App\Services\WageSplitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 32 — "Pembagian Upah per Orang" (File D): ⚙ Pengaturan › Tim Tukang
 * (templates + default weights) and a project's split per Kepala Tim.
 */
class WageSplitController extends Controller
{
    public function index(Request $request, WageSplitService $service): Response
    {
        return Inertia::render('WorkTeams/Index', [
            'levels' => $service->levelOptions(),
            'templates' => $service->templateOptions(),
            'canEditLevels' => $request->user()->hasAnyRole(['CEO', 'SUPERADMIN']),
        ]);
    }

    public function store(SaveWorkTeamRequest $request, WageSplitService $service): RedirectResponse
    {
        $service->saveTemplate(null, $request->validated(), $request->user());

        return back()->with('success', 'Tim tersimpan.');
    }

    public function update(SaveWorkTeamRequest $request, WorkTeam $workTeam, WageSplitService $service): RedirectResponse
    {
        $service->saveTemplate($workTeam, $request->validated(), $request->user());

        return back()->with('success', 'Tim tersimpan.');
    }

    public function destroy(Request $request, WorkTeam $workTeam, WageSplitService $service): RedirectResponse
    {
        $service->deleteTemplate($workTeam, $request->user());

        return back()->with('success', 'Tim dihapus.');
    }

    public function saveLevels(SaveCareerLevelsRequest $request, WageSplitService $service): RedirectResponse
    {
        $service->saveLevels($request->validated('levels'), $request->user());

        return back()->with('success', 'Bobot tingkatan tersimpan.');
    }

    /** A project's split for one Kepala Tim (inline — quiet partial reload). */
    public function saveProjectSplit(SaveProjectWageSplitRequest $request, Project $project, WageSplitService $service): RedirectResponse
    {
        $service->saveProjectSplit($project, $request->validated(), $request->user());

        return back()->with('success', 'Pembagian upah tersimpan.');
    }
}
