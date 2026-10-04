<?php

namespace App\Services;

use App\Enums\MaterialRequestStatus;
use App\Enums\ProjectMaterialSource;
use App\Enums\ProjectStatus;
use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 11 Sub 3 — a project material line's lifecycle (§5.2):
 *
 *   plan (catalog → GUDANG / PEMBELIAN, approved at once)
 *   → receive (GUDANG: issued from stock via StockService; PEMBELIAN /
 *     CUSTOM: purchase recorded with the actual price)
 *   → use (≤ what's on hand)
 *   → settle the leftover: return to stock, waste (reason), or hand over
 *     to the client (note).
 *
 * leftover = received − used − returned − wasted − handed over, never
 * negative. Every step runs in a transaction holding a lock on the line
 * (then on the material, StockService's order), so two concurrent
 * actions can't both spend the same leftover. Only DISETUJUI lines move.
 * Who may call what — Logistics, or the PM on their own project — is
 * ProjectPolicy + route middleware; the rules on the stored row live here.
 */
class ProjectMaterialService
{
    public function __construct(
        private StockService $stockService,
        private AuditLogService $auditLogService,
        private ProjectService $projectService,
        private MaterialCatalogService $catalogService,
    ) {}

    /**
     * "PM/Estimator mencatat kebutuhan material per proyek" from the
     * catalog. Planning the same item from the same source again updates
     * that line instead of adding a duplicate.
     */
    public function plan(Project $project, array $data, User $actor): ProjectMaterial
    {
        $this->ensureRunning($project, 'material_id');

        $material = Material::findOrFail($data['material_id']);

        // A line born from a request (Sub 4) keeps its own history and isn't merged into.
        $line = ProjectMaterial::firstOrNew([
            'project_id' => $project->id,
            'material_id' => $material->id,
            'source' => $data['source'],
            'request_channel' => null,
        ]);

        $line->fill([
            'unit_id' => $material->unit_id,
            'qty_planned' => $data['qty_planned'],
            'vendor_id' => $data['source'] === ProjectMaterialSource::Pembelian->value ? ($data['vendor_id'] ?? $line->vendor_id) : null,
            'request_status' => MaterialRequestStatus::Disetujui->value,
            'requested_by' => $line->requested_by ?? $actor->id,
        ])->save();

        return $line;
    }

    public function updatePlan(ProjectMaterial $line, array $data): ProjectMaterial
    {
        $line->update([
            'qty_planned' => $data['qty_planned'],
            ...($line->source->isPurchased() && array_key_exists('vendor_id', $data) ? ['vendor_id' => $data['vendor_id']] : []),
        ]);

        return $line;
    }

    /** Only a line nothing was received on can be removed — once goods arrived, it's history. */
    public function removePlan(ProjectMaterial $line): void
    {
        if (Quantity::toHundredths($line->qty_received) > 0) {
            throw ValidationException::withMessages([
                'qty_used' => 'Material ini sudah diterima proyek — tidak bisa dihapus dari daftar kebutuhan.',
            ]);
        }

        $line->delete();
    }

    /** GUDANG: Logistics issues stock to the project, charged at the warehouse price. */
    public function issue(ProjectMaterial $line, array $data, User $actor): StockMovement
    {
        if ($line->source !== ProjectMaterialSource::Gudang) {
            throw ValidationException::withMessages([
                'qty' => 'Hanya baris sumber Gudang yang dikeluarkan dari stok — baris ini dicatat lewat pembelian.',
            ]);
        }

        return $this->stockService->stockOut($line->material, $line->project, $data, $actor, $line);
    }

    /**
     * PEMBELIAN / CUSTOM: bought for this project — received qty grows and
     * the project is charged the actual price (decision #5's counterpart:
     * purchases cost what they cost). `unit_price` keeps the latest price.
     */
    public function recordPurchase(ProjectMaterial $line, array $data, User $actor): ProjectMaterial
    {
        return DB::transaction(function () use ($line, $data, $actor) {
            $line = $this->lockApproved($line, 'qty');
            $this->ensureRunning($line->project, 'qty');

            if (! $line->source->isPurchased()) {
                throw ValidationException::withMessages([
                    'qty' => 'Baris sumber Gudang diterima lewat "Keluarkan dari Gudang", bukan pembelian.',
                ]);
            }

            $qty = Quantity::toHundredths($data['qty']);
            $cost = round(Quantity::fromHundredths($qty) * (float) $data['unit_price'], 2);
            $before = $line->only(['qty_received', 'cost_total', 'unit_price', 'vendor_id']);

            $line->forceFill([
                'qty_received' => Quantity::fromHundredths(Quantity::toHundredths($line->qty_received) + $qty),
                'cost_total' => round((float) $line->cost_total + $cost, 2),
                'unit_price' => $data['unit_price'],
                'vendor_id' => $data['vendor_id'] ?? $line->vendor_id,
            ])->save();

            $this->audit('logistics.material_purchased', $line, $before, [
                'qty' => Quantity::fromHundredths($qty),
                'unit_price' => $data['unit_price'],
                'purchase_date' => $data['purchase_date'] ?? null,
                'note' => $data['note'] ?? null,
            ], $actor);

            return $line;
        });
    }

    /** Usage can't exceed what's on hand (received minus everything already accounted for). */
    public function recordUsage(ProjectMaterial $line, array $data, User $actor): ProjectMaterial
    {
        return DB::transaction(function () use ($line, $data, $actor) {
            $line = $this->lockApproved($line, 'qty');
            $this->ensureRunning($line->project, 'qty');
            $qty = $this->takeFromLeftover($line, $data['qty'], 'dipakai');
            $before = $line->only(['qty_used']);

            $line->forceFill(['qty_used' => Quantity::fromHundredths(Quantity::toHundredths($line->qty_used) + $qty)])->save();

            $this->audit('logistics.material_used', $line, $before, ['qty' => Quantity::fromHundredths($qty), 'note' => $data['note'] ?? null], $actor);

            return $line;
        });
    }

    /**
     * Leftover back into Logistics' stock (Logistics only). A CUSTOM line
     * has no catalog item of its own — Logistics maps it to an existing
     * one with the same unit, or registers it as a new catalog item with
     * its warehouse price (`new_material`, Sub 4), first (§5.5 / decision
     * #12). The origin project's cost is not reduced (decision #9).
     */
    public function returnToWarehouse(ProjectMaterial $line, array $data, User $actor): StockMovement
    {
        return DB::transaction(function () use ($line, $data, $actor) {
            $line = $this->lockApproved($line, 'qty');
            $qty = $this->takeFromLeftover($line, $data['qty'], 'diretur');
            $target = $this->returnTarget($line, $data, $actor);
            $before = $line->only(['qty_returned']);

            $line->forceFill(['qty_returned' => Quantity::fromHundredths(Quantity::toHundredths($line->qty_returned) + $qty)])->save();

            $movement = $this->stockService->returnFromProject($line, $target, $qty, $data, $actor);

            $this->audit('logistics.material_returned', $line, $before, [
                'qty' => Quantity::fromHundredths($qty),
                'material_id' => $target->id,
                'stock_movement_id' => $movement->id,
            ], $actor);

            $this->projectService->completeIfFinished($line->project);

            return $movement;
        });
    }

    /** Susut — not returned to stock; the reason is mandatory (decision #12). */
    public function recordWaste(ProjectMaterial $line, array $data, User $actor): ProjectMaterial
    {
        return $this->settle($line, $data, $actor, 'qty_wasted', 'waste_reason', $data['reason'], 'disusutkan', 'logistics.material_wasted');
    }

    /** Handed to the client (e.g. offcuts of their glass) — not returned to stock; the note is mandatory. */
    public function handOverToClient(ProjectMaterial $line, array $data, User $actor): ProjectMaterial
    {
        return $this->settle($line, $data, $actor, 'qty_handed_over', 'handover_note', $data['note'], 'diserahkan', 'logistics.material_handed_over');
    }

    private function settle(
        ProjectMaterial $line,
        array $data,
        User $actor,
        string $qtyColumn,
        string $noteColumn,
        string $note,
        string $verb,
        string $auditAction,
    ): ProjectMaterial {
        return DB::transaction(function () use ($line, $data, $actor, $qtyColumn, $noteColumn, $note, $verb, $auditAction) {
            $line = $this->lockApproved($line, 'qty');
            $qty = $this->takeFromLeftover($line, $data['qty'], $verb);
            $before = $line->only([$qtyColumn]);

            // Every settlement keeps its own line of explanation.
            $entry = now('Asia/Jakarta')->format('d/m/Y').' · '.$line->quantityLabel(Quantity::fromHundredths($qty)).' — '.trim($note);

            $line->forceFill([
                $qtyColumn => Quantity::fromHundredths(Quantity::toHundredths($line->{$qtyColumn}) + $qty),
                $noteColumn => trim(($line->{$noteColumn} ? $line->{$noteColumn}."\n" : '').$entry),
            ])->save();

            $this->audit($auditAction, $line, $before, ['qty' => Quantity::fromHundredths($qty), 'note' => $note], $actor);

            $this->projectService->completeIfFinished($line->project);

            return $line;
        });
    }

    /** Re-read the line under a lock and refuse anything not approved yet. */
    private function lockApproved(ProjectMaterial $line, string $field): ProjectMaterial
    {
        $locked = ProjectMaterial::query()->with(['project', 'material', 'unit'])->lockForUpdate()->findOrFail($line->id);

        if ($locked->request_status !== MaterialRequestStatus::Disetujui) {
            throw ValidationException::withMessages([
                $field => 'Baris material ini belum disetujui Logistik.',
            ]);
        }

        return $locked;
    }

    /** @return int the qty in hundredths, after checking it fits in the leftover. */
    private function takeFromLeftover(ProjectMaterial $line, mixed $qty, string $verb): int
    {
        $hundredths = Quantity::toHundredths($qty);
        $leftover = $line->leftoverHundredths();

        if ($hundredths <= 0) {
            throw ValidationException::withMessages(['qty' => 'Jumlah harus lebih dari 0.']);
        }

        if ($hundredths > $leftover) {
            throw ValidationException::withMessages([
                'qty' => "Jumlah yang {$verb} melebihi sisa {$line->display_name} di proyek — tersisa {$line->quantityLabel(Quantity::fromHundredths($leftover))}.",
            ]);
        }

        return $hundredths;
    }

    private function returnTarget(ProjectMaterial $line, array $data, User $actor): Material
    {
        if ($line->material_id) {
            return $line->material;
        }

        if (! empty($data['new_material'])) {
            // Same anti-duplicate rules as any new catalog item (§5.5).
            return $this->catalogService->create([
                ...$data['new_material'],
                'base_name' => $data['new_material']['name'],
                'unit_id' => $line->unit_id,
            ], $actor, 'new_material.', 'name');
        }

        $materialId = $data['material_id'] ?? null;

        if (! $materialId) {
            throw ValidationException::withMessages([
                'material_id' => 'Barang custom wajib dipetakan ke barang katalog sebelum diretur ke gudang.',
            ]);
        }

        $material = Material::findOrFail($materialId);

        if ((int) $material->unit_id !== (int) $line->unit_id) {
            throw ValidationException::withMessages([
                'material_id' => "Satuan {$material->name} ({$material->unit?->code}) berbeda dengan barang custom ({$line->unit?->code}) — pilih barang dengan satuan yang sama.",
            ]);
        }

        return $material;
    }

    /** Receiving and using need a running project; settling leftovers is allowed on a cancelled one too. */
    private function ensureRunning(Project $project, string $field): void
    {
        if (! in_array($project->status, [ProjectStatus::Active, ProjectStatus::OnHold], true)) {
            throw ValidationException::withMessages([
                $field => 'Proyek ini sudah '.$project->status->value.' — material tidak bisa diterima atau dipakai lagi.',
            ]);
        }
    }

    private function audit(string $action, ProjectMaterial $line, array $before, array $extra, User $actor): void
    {
        $this->auditLogService->record($action, $line, $before, [
            ...$line->only(array_keys($before)),
            'project_id' => $line->project_id,
            'item' => $line->display_name,
            ...$extra,
        ], $actor);
    }
}
