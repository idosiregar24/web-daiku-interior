<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\StoreProjectMaterialRequest;
use App\Http\Requests\Logistics\UpdateProjectMaterialRequest;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Services\LogisticsService;
use Illuminate\Http\RedirectResponse;

/**
 * PRD §4.8 "Kebutuhan Material Proyek" — listed on the project's own
 * Material tab (ProjectController::show()'s `projectMaterials` prop), so
 * only write actions live here. See routes/web.php for the role split.
 */
class ProjectMaterialController extends Controller
{
    public function store(StoreProjectMaterialRequest $request, Project $project, LogisticsService $service): RedirectResponse
    {
        $service->planMaterial($project, $request->validated());

        return back()->with('success', 'Kebutuhan material disimpan.');
    }

    public function update(UpdateProjectMaterialRequest $request, ProjectMaterial $projectMaterial, LogisticsService $service): RedirectResponse
    {
        $service->updatePlan($projectMaterial, $request->validated());

        return back()->with('success', 'Kebutuhan material diperbarui.');
    }

    public function destroy(ProjectMaterial $projectMaterial, LogisticsService $service): RedirectResponse
    {
        $service->removePlan($projectMaterial);

        return back()->with('success', 'Material dihapus dari kebutuhan proyek.');
    }
}
