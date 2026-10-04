<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\ProjectMaterialActionRequest;
use App\Http\Requests\Logistics\StoreProjectMaterialRequest;
use App\Http\Requests\Logistics\UpdateProjectMaterialRequest;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Services\ProjectMaterialService;
use App\Support\Quantity;
use Illuminate\Http\RedirectResponse;

/**
 * PRD §4.8 "Kebutuhan Material Proyek" + Sprint 11 Sub 3 lifecycle —
 * listed on the project's own Material tab (ProjectController::show()),
 * so only write actions live here. Role split in routes/web.php;
 * "PM only on their own project" in ProjectPolicy (§5.6).
 */
class ProjectMaterialController extends Controller
{
    public function store(StoreProjectMaterialRequest $request, Project $project, ProjectMaterialService $service): RedirectResponse
    {
        $this->authorize('planMaterials', $project);

        $service->plan($project, $request->validated(), $request->user());

        return back()->with('success', 'Kebutuhan material disimpan.');
    }

    public function update(UpdateProjectMaterialRequest $request, ProjectMaterial $projectMaterial, ProjectMaterialService $service): RedirectResponse
    {
        $this->authorize('manageMaterials', $projectMaterial->project);

        $service->updatePlan($projectMaterial, $request->validated());

        return back()->with('success', 'Kebutuhan material diperbarui.');
    }

    public function destroy(ProjectMaterial $projectMaterial, ProjectMaterialService $service): RedirectResponse
    {
        $service->removePlan($projectMaterial);

        return back()->with('success', 'Material dihapus dari kebutuhan proyek.');
    }

    public function issue(ProjectMaterialActionRequest $request, ProjectMaterial $projectMaterial, ProjectMaterialService $service): RedirectResponse
    {
        $service->issue($projectMaterial, $request->validated(), $request->user());

        return back()->with('success', "{$this->label($projectMaterial, $request)} dikeluarkan dari gudang ke proyek.");
    }

    public function purchase(ProjectMaterialActionRequest $request, ProjectMaterial $projectMaterial, ProjectMaterialService $service): RedirectResponse
    {
        $this->authorize('manageMaterials', $projectMaterial->project);

        $service->recordPurchase($projectMaterial, $request->validated(), $request->user());

        return back()->with('success', "Pembelian {$this->label($projectMaterial, $request)} dicatat.");
    }

    public function usage(ProjectMaterialActionRequest $request, ProjectMaterial $projectMaterial, ProjectMaterialService $service): RedirectResponse
    {
        $this->authorize('manageMaterials', $projectMaterial->project);

        $service->recordUsage($projectMaterial, $request->validated(), $request->user());

        return back()->with('success', "Pemakaian {$this->label($projectMaterial, $request)} dicatat.");
    }

    public function returnToWarehouse(ProjectMaterialActionRequest $request, ProjectMaterial $projectMaterial, ProjectMaterialService $service): RedirectResponse
    {
        $service->returnToWarehouse($projectMaterial, $request->validated(), $request->user());

        return back()->with('success', "Sisa {$this->label($projectMaterial, $request)} diretur ke gudang.");
    }

    public function waste(ProjectMaterialActionRequest $request, ProjectMaterial $projectMaterial, ProjectMaterialService $service): RedirectResponse
    {
        $this->authorize('manageMaterials', $projectMaterial->project);

        $service->recordWaste($projectMaterial, $request->validated(), $request->user());

        return back()->with('success', "Susut {$this->label($projectMaterial, $request)} dicatat.");
    }

    public function handOver(ProjectMaterialActionRequest $request, ProjectMaterial $projectMaterial, ProjectMaterialService $service): RedirectResponse
    {
        $this->authorize('manageMaterials', $projectMaterial->project);

        $service->handOverToClient($projectMaterial, $request->validated(), $request->user());

        return back()->with('success', "Sisa {$this->label($projectMaterial, $request)} dicatat diserahkan ke klien.");
    }

    /** "2,5 lbr Triplek 17mm" for the flash message. */
    private function label(ProjectMaterial $line, ProjectMaterialActionRequest $request): string
    {
        return Quantity::format($request->validated('qty')).' '.$line->unit?->code.' '.$line->display_name;
    }
}
