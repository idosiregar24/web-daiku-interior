<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\StorePlanCustomMaterialRequest;
use App\Models\Project;
use App\Services\ProjectMaterialService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sprint 29 Sub 11 (E2/E3) — the Pengawas's / Admin Proyek's Rencana Bahan
 * on Detail Proyek: a custom line next to the catalog lines (those go
 * through projects.materials.store / storeMany), then "Ajukan ke Logistik".
 * Gate: route `role:QA|ADMIN_PROYEK` + ProjectPolicy::planMaterials() +
 * "plans through Logistik" (ProjectMaterialService::plansThroughLogistics()).
 */
class MaterialPlanController extends Controller
{
    public function storeCustom(StorePlanCustomMaterialRequest $request, Project $project, ProjectMaterialService $service): RedirectResponse
    {
        $this->authorizePlan($request, $project, $service);

        $service->planCustom($project, $request->validated(), $request->user());

        return back()->with('success', 'Bahan custom masuk Rencana Bahan.');
    }

    public function submit(Request $request, Project $project, ProjectMaterialService $service): RedirectResponse
    {
        $this->authorizePlan($request, $project, $service);

        $count = $service->submitPlan($project, $request->user());

        return back()->with('success', "{$count} bahan diajukan ke Logistik.");
    }

    private function authorizePlan(Request $request, Project $project, ProjectMaterialService $service): void
    {
        $this->authorize('planMaterials', $project);
        abort_unless($service->plansThroughLogistics($project, $request->user()), 403);
    }
}
