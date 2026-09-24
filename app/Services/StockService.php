<?php

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Enums\StockMovementType;
use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PRD §4.8 "Manajemen Stok" — the only place `materials.stock` changes.
 * Every change writes a StockMovement ledger row in the same
 * transaction, under a row lock on the material so two concurrent
 * stock-outs can't both pass the "enough stock" check (PRD: "Stok tidak
 * bisa negatif — sistem memblokir input jika qty melebihi stok
 * tersedia").
 */
class StockService
{
    public function __construct(private NotificationService $notificationService) {}

    /** "Input penerimaan barang (stok bertambah)". */
    public function stockIn(Material $material, array $data, User $actor): StockMovement
    {
        return DB::transaction(function () use ($material, $data, $actor) {
            $locked = Material::whereKey($material->id)->lockForUpdate()->firstOrFail();
            $newStock = $locked->stock + (int) $data['qty'];

            $locked->forceFill(['stock' => $newStock])->save();

            return $this->record($locked, StockMovementType::In, null, $data, $newStock, $actor);
        });
    }

    /**
     * "Input pemakaian barang per proyek (stok berkurang)". Usage always
     * belongs to a project ("tidak ada pemakaian floating") and is added
     * to that project's ProjectMaterial `qty_used` — created on the fly
     * (planned 0) if nobody planned this material for the project.
     */
    public function stockOut(Material $material, Project $project, array $data, User $actor): StockMovement
    {
        if (in_array($project->status, [ProjectStatus::Completed, ProjectStatus::Cancelled], true)) {
            throw ValidationException::withMessages([
                'project_id' => 'Pemakaian material hanya bisa dicatat untuk proyek yang masih berjalan.',
            ]);
        }

        $qty = (int) $data['qty'];

        return DB::transaction(function () use ($material, $project, $data, $qty, $actor) {
            $locked = Material::whereKey($material->id)->lockForUpdate()->firstOrFail();

            if ($qty > $locked->stock) {
                throw ValidationException::withMessages([
                    'qty' => "Stok {$locked->name} tidak cukup — tersedia {$locked->stock} {$locked->unit}.",
                ]);
            }

            $wasLow = $locked->is_low_stock;
            $newStock = $locked->stock - $qty;
            $locked->forceFill(['stock' => $newStock])->save();

            $projectMaterial = ProjectMaterial::firstOrCreate(
                ['project_id' => $project->id, 'material_id' => $locked->id],
                ['qty_planned' => 0],
            );
            $projectMaterial->increment('qty_used', $qty);

            $movement = $this->record($locked, StockMovementType::Out, $project, $data, $newStock, $actor);

            // PRD §4.8 "Alert jika stok di bawah minimum threshold" — only on
            // the crossing, not on every further stock-out while already low.
            if (! $wasLow && $locked->fresh()->is_low_stock) {
                $this->notificationService->notifyRoles(
                    ['LOGISTICS'],
                    'material_low_stock',
                    'Stok Material Menipis',
                    "Stok {$locked->name} tinggal {$newStock} {$locked->unit} (minimum {$locked->min_stock}).",
                    ['material_id' => $locked->id],
                );
            }

            return $movement;
        });
    }

    private function record(
        Material $material,
        StockMovementType $type,
        ?Project $project,
        array $data,
        int $stockAfter,
        User $actor,
    ): StockMovement {
        return StockMovement::create([
            'material_id' => $material->id,
            'project_id' => $project?->id,
            'type' => $type->value,
            'qty' => (int) $data['qty'],
            'stock_after' => $stockAfter,
            'movement_date' => $data['movement_date'] ?? now('Asia/Jakarta')->toDateString(),
            'note' => $data['note'] ?? null,
            'recorded_by' => $actor->id,
        ]);
    }
}
