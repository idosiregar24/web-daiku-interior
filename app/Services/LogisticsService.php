<?php

namespace App\Services;

use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectMaterial;
use Illuminate\Validation\ValidationException;

/**
 * PRD §4.8 material master + project material planning rules that go
 * beyond plain CRUD. Stock itself lives in StockService.
 */
class LogisticsService
{
    /**
     * PRD §4.8 "PM/Estimator mencatat kebutuhan material per proyek".
     * Planning a material the project already has updates that row
     * instead of adding a duplicate (unique project+material).
     */
    public function planMaterial(Project $project, array $data): ProjectMaterial
    {
        return ProjectMaterial::updateOrCreate(
            ['project_id' => $project->id, 'material_id' => $data['material_id']],
            ['qty_planned' => $data['qty_planned']],
        );
    }

    public function updatePlan(ProjectMaterial $projectMaterial, array $data): ProjectMaterial
    {
        $projectMaterial->update(['qty_planned' => $data['qty_planned']]);

        return $projectMaterial;
    }

    /** Only an unused plan can be removed — once stock went out against it, it's history. */
    public function removePlan(ProjectMaterial $projectMaterial): void
    {
        if ($projectMaterial->qty_used > 0) {
            throw ValidationException::withMessages([
                'qty_used' => 'Material ini sudah terpakai di proyek — tidak bisa dihapus dari daftar kebutuhan.',
            ]);
        }

        $projectMaterial->delete();
    }

    /**
     * A material with stock history or project usage is referenced by
     * the ledger (restrictOnDelete FKs) — refuse with a readable message
     * rather than surfacing a raw FK violation.
     */
    public function deleteMaterial(Material $material): void
    {
        if ($material->stockMovements()->exists() || $material->projectMaterials()->exists()) {
            throw ValidationException::withMessages([
                'material' => "Material {$material->name} sudah punya riwayat stok/pemakaian proyek dan tidak bisa dihapus.",
            ]);
        }

        $material->delete();
    }
}
