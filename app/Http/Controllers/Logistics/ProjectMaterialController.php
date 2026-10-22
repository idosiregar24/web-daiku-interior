<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\ProjectMaterialActionRequest;
use App\Http\Requests\Logistics\StoreProjectMaterialRequest;
use App\Http\Requests\Logistics\StoreProjectMaterialsRequest;
use App\Http\Requests\Logistics\UpdateProjectMaterialRequest;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Services\ProjectMaterialService;
use App\Support\Quantity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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

        $line = $service->plan($project, $request->validated(), $request->user());

        return back()->with('success', $line->request_channel === ProjectMaterial::CHANNEL_RENCANA
            ? 'Bahan masuk Rencana Bahan — ajukan ke Logistik bila sudah lengkap.'
            : 'Kebutuhan material disimpan.');
    }

    /** Sprint 29 Sub 01 — "Simpan semua" of the multi-line material form; same gate as store(). */
    public function storeMany(StoreProjectMaterialsRequest $request, Project $project, ProjectMaterialService $service): RedirectResponse
    {
        $this->authorize('planMaterials', $project);

        $lines = $service->planMany($project, $request->validated('lines'), $request->user());

        return back()->with('success', count($lines).' kebutuhan material disimpan.');
    }

    public function update(UpdateProjectMaterialRequest $request, ProjectMaterial $projectMaterial, ProjectMaterialService $service): RedirectResponse
    {
        $this->authorizeRevision($request, $projectMaterial, 'manageMaterials');

        $service->updatePlan($projectMaterial, $request->validated());

        return back()->with('success', 'Kebutuhan material diperbarui.');
    }

    public function destroy(Request $request, ProjectMaterial $projectMaterial, ProjectMaterialService $service): RedirectResponse
    {
        if (! $request->user()->hasRole('LOGISTICS') || $projectMaterial->request_channel === ProjectMaterial::CHANNEL_RENCANA) {
            $this->authorizeRevision($request, $projectMaterial, null);
        }

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

    /**
     * Sprint 24 — besides `$ability` (Logistics / the PM), the project's
     * Pengawas may revise a catalog line of their plan before production
     * (ProjectPolicy::reviseMaterialPlan); a Tukang's request keeps its own flow.
     */
    private function authorizeRevision(Request $request, ProjectMaterial $line, ?string $ability): void
    {
        // Sprint 29 Sub 11 (E5) — a Rencana Bahan line: its planners' until submitted, then Logistik's.
        if ($line->request_channel === ProjectMaterial::CHANNEL_RENCANA) {
            $this->authorize('revisePlan', $line);

            return;
        }

        if ($ability !== null && $request->user()->can($ability, $line->project)) {
            return;
        }

        $this->authorize('reviseMaterialPlan', $line->project);
        abort_unless($line->material_id !== null && $line->request_channel === null, 403);
    }
}
