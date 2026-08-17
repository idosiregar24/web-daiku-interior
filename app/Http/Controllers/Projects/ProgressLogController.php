<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProgressLogRequest;
use App\Models\Project;
use App\Services\ProgressLogService;
use Illuminate\Http\RedirectResponse;

/**
 * PRD §4.4 "Progress Log" + §7.1 "Progress Log" row (PM CRUD, CEO/DES/
 * QA/FIN read). Read access is embedded in ProjectController::show()'s
 * `progressLogs` prop (same pattern as milestones/tasks), not a separate
 * index route — the timeline is scoped to one project's page.
 */
class ProgressLogController extends Controller
{
    public function store(StoreProgressLogRequest $request, Project $project, ProgressLogService $service): RedirectResponse
    {
        $service->create($project, $request->validated(), $request->user());

        return back()->with('success', 'Progress log berhasil ditambahkan.');
    }
}
