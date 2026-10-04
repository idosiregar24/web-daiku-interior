<?php

namespace App\Services;

use App\Models\Material;
use Illuminate\Validation\ValidationException;

/**
 * PRD §4.8 material master rules that go beyond plain CRUD. Stock lives
 * in StockService; project material lines in ProjectMaterialService;
 * creating catalog items (anti-duplicate rules) in MaterialCatalogService.
 */
class LogisticsService
{
    /**
     * A material with stock history or project lines is referenced by
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
