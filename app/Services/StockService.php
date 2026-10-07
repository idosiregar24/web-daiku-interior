<?php

namespace App\Services;

use App\Enums\MaterialRequestStatus;
use App\Enums\NotificationType;
use App\Enums\ProjectMaterialSource;
use App\Enums\ProjectStatus;
use App\Enums\StockMovementType;
use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PRD §4.8 "Manajemen Stok" — the only place `materials.stock` changes.
 * Every change writes a StockMovement ledger row in the same
 * transaction, under a row lock on the material so two concurrent
 * stock-outs can't both pass the "enough stock" check (PRD: "Stok tidak
 * bisa negatif — sistem memblokir input jika qty melebihi stok
 * tersedia"). Quantities may be fractional (Sprint 11 decision #3) and
 * are compared in whole hundredths, never as floats.
 *
 * Lock order is always project material line → material, so an issue and
 * a return on the same line can't deadlock each other.
 */
class StockService
{
    public function __construct(private NotificationService $notificationService) {}

    /** "Input penerimaan barang (stok bertambah)". */
    public function stockIn(Material $material, array $data, User $actor): StockMovement
    {
        return DB::transaction(function () use ($material, $data, $actor) {
            $locked = Material::whereKey($material->id)->lockForUpdate()->firstOrFail();
            $newStock = Quantity::toHundredths($locked->stock) + Quantity::toHundredths($data['qty']);

            $locked->forceFill(['stock' => Quantity::fromHundredths($newStock)])->save();

            return $this->record($locked, StockMovementType::In, null, null, $data, $newStock, null, $actor);
        });
    }

    /**
     * Issue stock to a project (Sprint 11 §5.2 "Penerimaan — GUDANG").
     * Always lands on the project's GUDANG line for this material —
     * created on the fly (planned 0) if nobody planned it — raising its
     * received qty, and charges the project the warehouse price
     * (`materials.cost_price`), snapshotted on the movement so a later
     * catalog price change never rewrites the recorded cost (decision #5).
     * What the project then actually uses is recorded separately.
     */
    public function stockOut(Material $material, Project $project, array $data, User $actor, ?ProjectMaterial $target = null): StockMovement
    {
        if (! in_array($project->status, [ProjectStatus::Active, ProjectStatus::OnHold], true)) {
            throw ValidationException::withMessages([
                'project_id' => 'Barang hanya bisa dikeluarkan untuk proyek yang masih berjalan.',
            ]);
        }

        $qty = Quantity::toHundredths($data['qty']);

        return DB::transaction(function () use ($material, $project, $data, $qty, $actor, $target) {
            // From a line's own "Keluarkan" action the line is given; from the
            // Materials page it's the project's first GUDANG line for the item.
            $line = $target ?? ProjectMaterial::query()
                ->where('project_id', $project->id)
                ->where('material_id', $material->id)
                ->where('source', ProjectMaterialSource::Gudang->value)
                ->approved()
                ->oldest('id')
                ->first()
                ?? ProjectMaterial::create([
                    'project_id' => $project->id,
                    'material_id' => $material->id,
                    'source' => ProjectMaterialSource::Gudang->value,
                    'unit_id' => $material->unit_id,
                    'qty_planned' => 0,
                    'request_status' => MaterialRequestStatus::Disetujui->value,
                    'requested_by' => $actor->id,
                ]);
            $line = ProjectMaterial::whereKey($line->id)->lockForUpdate()->firstOrFail();

            if ((int) $line->project_id !== (int) $project->id || (int) $line->material_id !== (int) $material->id) {
                throw ValidationException::withMessages(['qty' => 'Baris material tidak cocok dengan barang/proyek ini.']);
            }
            $locked = Material::whereKey($material->id)->lockForUpdate()->firstOrFail();

            if ($line->request_status !== MaterialRequestStatus::Disetujui) {
                throw ValidationException::withMessages([
                    'qty' => 'Baris material ini belum disetujui Logistik.',
                ]);
            }

            if ($qty > Quantity::toHundredths($locked->stock)) {
                throw ValidationException::withMessages([
                    'qty' => "Stok {$locked->name} tidak cukup — tersedia {$locked->quantityLabel($locked->stock)}.",
                ]);
            }

            $wasLow = $locked->is_low_stock;
            $newStock = Quantity::toHundredths($locked->stock) - $qty;
            $locked->forceFill(['stock' => Quantity::fromHundredths($newStock)])->save();

            $unitCost = (string) $locked->cost_price;
            $line->forceFill([
                'qty_received' => Quantity::fromHundredths(Quantity::toHundredths($line->qty_received) + $qty),
                'cost_total' => round((float) $line->cost_total + Quantity::fromHundredths($qty) * (float) $unitCost, 2),
            ])->save();

            $movement = $this->record($locked, StockMovementType::Out, $project, $line, $data, $newStock, $unitCost, $actor);

            // PRD §4.8 "Alert jika stok di bawah minimum threshold" — only on
            // the crossing, not on every further stock-out while already low.
            if (! $wasLow && $locked->fresh()->is_low_stock) {
                $this->notificationService->notifyRoles(
                    ['LOGISTICS'],
                    NotificationType::MaterialLowStock,
                    'Stok Material Menipis',
                    "Stok {$locked->name} tinggal {$locked->quantityLabel(Quantity::fromHundredths($newStock))} (minimum {$locked->quantityLabel($locked->min_stock)}).",
                    ['material_id' => $locked->id],
                );
            }

            return $movement;
        });
    }

    /**
     * A project's leftover goes back into stock (Sprint 11 §5.2 "Retur ke
     * gudang"). Called by ProjectMaterialService, which has already locked
     * and validated the line. `$material` is the catalog item the stock
     * lands on — the line's own item, or for a CUSTOM line the catalog
     * item Logistics mapped it to. The return never lowers the origin
     * project's cost (decision #9); `unit_cost` only records the value.
     */
    public function returnFromProject(ProjectMaterial $line, Material $material, int $qtyHundredths, array $data, User $actor): StockMovement
    {
        return DB::transaction(function () use ($line, $material, $qtyHundredths, $data, $actor) {
            $locked = Material::whereKey($material->id)->lockForUpdate()->firstOrFail();
            $newStock = Quantity::toHundredths($locked->stock) + $qtyHundredths;
            $locked->forceFill(['stock' => Quantity::fromHundredths($newStock)])->save();

            $unitCost = $line->source === ProjectMaterialSource::Gudang ? (string) $locked->cost_price : $line->unit_price;

            return $this->record(
                $locked,
                StockMovementType::Return,
                $line->project,
                $line,
                [...$data, 'qty' => Quantity::fromHundredths($qtyHundredths)],
                $newStock,
                $unitCost,
                $actor,
            );
        });
    }

    /**
     * Sprint 11 §5.5 Lapis 6 — merging catalog item B into A moves all of
     * B's stock through the ledger: MERGE_OUT on B (to 0), MERGE_IN on A.
     * Called by MaterialCatalogService::merge(), inside its transaction.
     */
    public function transferForMerge(Material $from, Material $into, User $actor): void
    {
        DB::transaction(function () use ($from, $into, $actor) {
            $source = Material::whereKey($from->id)->lockForUpdate()->firstOrFail();
            $target = Material::whereKey($into->id)->lockForUpdate()->firstOrFail();
            $qty = Quantity::toHundredths($source->stock);

            if ($qty <= 0) {
                return;
            }

            $note = ['note' => "Gabung barang {$source->code} → {$target->code}", 'qty' => Quantity::fromHundredths($qty)];

            $source->forceFill(['stock' => 0])->save();
            $this->record($source, StockMovementType::MergeOut, null, null, $note, 0, (string) $source->cost_price, $actor);

            $newStock = Quantity::toHundredths($target->stock) + $qty;
            $target->forceFill(['stock' => Quantity::fromHundredths($newStock)])->save();
            $this->record($target, StockMovementType::MergeIn, null, null, $note, $newStock, (string) $target->cost_price, $actor);
        });
    }

    private function record(
        Material $material,
        StockMovementType $type,
        ?Project $project,
        ?ProjectMaterial $line,
        array $data,
        int $stockAfterHundredths,
        ?string $unitCost,
        User $actor,
    ): StockMovement {
        return StockMovement::create([
            'material_id' => $material->id,
            'project_id' => $project?->id,
            'project_material_id' => $line?->id,
            'type' => $type->value,
            'qty' => Quantity::fromHundredths(Quantity::toHundredths($data['qty'])),
            'stock_after' => Quantity::fromHundredths($stockAfterHundredths),
            'unit_cost' => $unitCost,
            'movement_date' => $data['movement_date'] ?? now('Asia/Jakarta')->toDateString(),
            'note' => $data['note'] ?? null,
            'recorded_by' => $actor->id,
        ]);
    }
}
