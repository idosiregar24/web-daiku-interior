<?php

namespace App\Services;

use App\Enums\MaterialRequestStatus;
use App\Enums\NotificationType;
use App\Enums\ProjectMaterialSource;
use App\Enums\ProjectStatus;
use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
 *
 * Sprint 29 Sub 11 (E1–E5) — the Pengawas / Admin Proyek plan through
 * Logistik instead ("Rencana Bahan", `request_channel` RENCANA):
 *
 *   plan / planCustom ─▶ DIRENCANAKAN (their draft; the server picks
 *   GUDANG / PEMBELIAN from the stock) ── submitPlan ─▶ DIAJUKAN
 *   ── decidePlan (catalog) / MaterialRequestService::review (custom) ─▶
 *   DISETUJUI / DITOLAK ── markOrdered ─▶ "Dipesan" ── recordPurchase /
 *   issue ─▶ "Diterima sebagian / Lengkap / Dari gudang"
 *   (ProjectMaterial::orderStatus()).
 */
class ProjectMaterialService
{
    public function __construct(
        private StockService $stockService,
        private AuditLogService $auditLogService,
        private ProjectService $projectService,
        private MaterialCatalogService $catalogService,
        private NotificationService $notificationService,
    ) {}

    /**
     * Sprint 29 Sub 11 — whose plan goes through Logistik: the project's
     * Pengawas and the Admin Proyek, unless they also plan directly
     * (Logistics, the project's own PM, an Estimator).
     */
    public function plansThroughLogistics(Project $project, User $user): bool
    {
        if ($user->hasRole('ESTIMATOR') || $user->can('manageMaterials', $project)) {
            return false;
        }

        return $project->isSupervisedBy($user) || $project->isAdministeredBy($user);
    }

    /**
     * "PM/Estimator mencatat kebutuhan material per proyek" from the
     * catalog. Planning the same item from the same source again updates
     * that line instead of adding a duplicate.
     */
    public function plan(Project $project, array $data, User $actor): ProjectMaterial
    {
        $this->ensureRunning($project, 'material_id');

        $material = Material::findOrFail($data['material_id']);

        if ($this->plansThroughLogistics($project, $actor)) {
            return $this->planDraft($project, $material, $data['qty_planned'], $actor);
        }

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

    /**
     * Sprint 29 Sub 01 (L1/K5) — "Simpan semua": every line through plan(),
     * one transaction — one refused line saves none. The same catalog item
     * from the same source twice in one batch is refused (plan() would
     * silently keep only the last quantity).
     *
     * @param  list<array{material_id: int, source: string, qty_planned: float|string, vendor_id?: int|null}>  $lines
     * @return list<ProjectMaterial>
     */
    public function planMany(Project $project, array $lines, User $actor): array
    {
        $seen = [];

        foreach (array_values($lines) as $index => $line) {
            // A Rencana Bahan line has no source of its own yet (the server picks it).
            $key = $line['material_id'].'|'.($line['source'] ?? ProjectMaterial::CHANNEL_RENCANA);

            if (isset($seen[$key])) {
                $name = Material::whereKey($line['material_id'])->value('name');

                throw ValidationException::withMessages([
                    "lines.{$index}.material_id" => 'Baris '.($index + 1)." — \"{$name}\" dari sumber yang sama sudah ada di baris ".($seen[$key] + 1).'. Gabungkan jumlahnya.',
                ]);
            }

            $seen[$key] = $index;
        }

        return DB::transaction(fn () => array_map(fn (array $line) => $this->plan($project, $line, $actor), array_values($lines)));
    }

    /**
     * Sprint 29 Sub 11 (E2) — a catalog line of the Rencana Bahan. Planning
     * the same item again while it is still a draft changes that line's
     * amount; once submitted, a new draft line is added instead.
     */
    private function planDraft(Project $project, Material $material, float|int|string $qty, User $actor): ProjectMaterial
    {
        $line = ProjectMaterial::query()
            ->where('project_id', $project->id)
            ->where('material_id', $material->id)
            ->fromPlan()
            ->where('request_status', MaterialRequestStatus::Direncanakan->value)
            ->first()
            ?? new ProjectMaterial([
                'project_id' => $project->id,
                'material_id' => $material->id,
                'request_channel' => ProjectMaterial::CHANNEL_RENCANA,
                'request_status' => MaterialRequestStatus::Direncanakan->value,
                'requested_by' => $actor->id,
            ]);

        $line->fill([
            'unit_id' => $material->unit_id,
            'qty_planned' => $qty,
            'source' => $this->sourceFor($material, $qty)->value,
        ])->save();

        return $line;
    }

    /**
     * Sprint 29 Sub 11 (E2) — "+ Bahan lain (custom)": an item outside the
     * catalog in the Rencana Bahan. It waits as a CUSTOM placeholder and,
     * once submitted, goes through Logistik's catalog review like any
     * out-of-catalog request (MaterialRequestService::review(), golden rule #10).
     */
    public function planCustom(Project $project, array $data, User $actor): ProjectMaterial
    {
        $this->ensureRunning($project, 'name');

        $snapshot = array_filter([
            'name' => trim($data['name']),
            'spec' => $data['spec'] ?? null,
            'unit_id' => $data['unit_id'] ?? null,
            'qty' => (float) $data['qty'],
            'reason' => $data['note'] ?? null,
            'photo_link' => $data['photo_link'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        return ProjectMaterial::create([
            'project_id' => $project->id,
            'source' => ProjectMaterialSource::Custom->value,
            'material_id' => null,
            'custom_name' => $snapshot['name'],
            'custom_spec' => $data['spec'] ?? null,
            'unit_id' => $data['unit_id'],
            'qty_planned' => $data['qty'],
            'request_status' => MaterialRequestStatus::Direncanakan->value,
            'request_channel' => ProjectMaterial::CHANNEL_RENCANA,
            'request_reason' => $data['note'] ?? null,
            'photo_link' => $data['photo_link'] ?? null,
            'requested_by' => $actor->id,
            'requested_snapshot' => $snapshot,
        ]);
    }

    /**
     * Sprint 29 Sub 11 (E3) — "Ajukan ke Logistik (n bahan)": every draft
     * line of the project's Rencana Bahan becomes DIAJUKAN at once, the
     * catalog lines' source re-read from the stock of that moment, and
     * Logistik hears it once.
     *
     * @return int how many lines were submitted
     */
    public function submitPlan(Project $project, User $actor): int
    {
        $this->ensureRunning($project, 'plan');

        return DB::transaction(function () use ($project, $actor) {
            $lines = $project->projectMaterials()->getQuery()
                ->fromPlan()
                ->where('request_status', MaterialRequestStatus::Direncanakan->value)
                ->with(['material', 'unit:id,code'])
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['plan' => 'Belum ada bahan yang direncanakan untuk diajukan ke Logistik.']);
            }

            foreach ($lines as $line) {
                $line->forceFill([
                    'request_status' => MaterialRequestStatus::Diajukan->value,
                    'submitted_at' => now(),
                    ...($line->material ? ['source' => $this->sourceFor($line->material, $line->qty_planned)->value] : []),
                ])->save();
            }

            $this->auditLogService->record('logistics.material_plan_submitted', $project, null, [
                'project_material_ids' => $lines->pluck('id')->all(),
                'items' => $lines->map(fn (ProjectMaterial $line) => "{$line->display_name} ({$line->quantityLabel($line->qty_planned)})")->all(),
            ], $actor);

            $this->notificationService->notifyRoles(
                ['LOGISTICS'],
                NotificationType::MaterialPlanSubmitted,
                'Pengajuan Bahan Baru',
                "{$actor->name} mengajukan {$lines->count()} bahan untuk proyek \"{$project->name}\": {$this->listText($lines)}. Proses di menu Pemesanan Bahan.",
                ['project_id' => $project->id, 'project_material_ids' => $lines->pluck('id')->all()],
            );

            return $lines->count();
        });
    }

    /**
     * Sprint 29 Sub 11 (E3) — Logistik's Setujui / Tolak on submitted lines
     * of a Rencana Bahan, one or many at once. Approving (catalog lines only
     * — a custom line is matched to the catalog through
     * MaterialRequestService::review()) may change the source (GUDANG /
     * PEMBELIAN) and set a bought line's vendor; rejecting needs a reason.
     * The Pengawas hears a rejection.
     *
     * @param  list<int>  $ids
     * @return Collection<int, ProjectMaterial>
     */
    public function decidePlan(array $ids, string $decision, array $data, User $actor): Collection
    {
        return DB::transaction(function () use ($ids, $decision, $data, $actor) {
            $lines = $this->lockPlanLines($ids, MaterialRequestStatus::Diajukan, 'decision', 'sudah diputuskan');
            $approve = $decision === 'approve';
            $reason = trim((string) ($data['reason'] ?? ''));

            if (! $approve && $reason === '') {
                throw ValidationException::withMessages(['reason' => 'Alasan penolakan wajib diisi.']);
            }

            if ($approve && ($custom = $lines->first(fn (ProjectMaterial $line) => $line->material_id === null))) {
                throw ValidationException::withMessages([
                    'decision' => "{$custom->display_name} barang di luar katalog — tinjau satu per satu (cocokkan ke katalog atau daftarkan).",
                ]);
            }

            foreach ($lines as $line) {
                $before = $line->only(['request_status', 'source', 'vendor_id']);
                $source = $approve && ! empty($data['source']) ? ProjectMaterialSource::from($data['source']) : $line->source;

                $line->forceFill($approve ? [
                    'request_status' => MaterialRequestStatus::Disetujui->value,
                    'review_decision' => ProjectMaterial::DECISION_PAKAI_KATALOG,
                    'source' => $source->value,
                    'vendor_id' => $source === ProjectMaterialSource::Pembelian ? ($data['vendor_id'] ?? $line->vendor_id) : null,
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => now(),
                ] : [
                    'request_status' => MaterialRequestStatus::Ditolak->value,
                    'review_decision' => ProjectMaterial::DECISION_TOLAK,
                    'reject_reason' => $reason,
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => now(),
                ])->save();

                $this->audit($approve ? 'logistics.material_plan_approved' : 'logistics.material_plan_rejected', $line, $before, [
                    'reject_reason' => $line->reject_reason,
                ], $actor);
            }

            if (! $approve) {
                $lines->groupBy('project_id')->each(function (Collection $rows) use ($reason) {
                    $project = $rows->first()->project;
                    $this->notifyPlanners($project, $rows, NotificationType::MaterialPlanRejected, 'Bahan Ditolak Logistik',
                        "Logistik menolak {$rows->count()} bahan proyek \"{$project->name}\": {$this->listText($rows)} — {$reason}");
                    $this->projectService->completeIfFinished($project);
                });
            }

            return $lines;
        });
    }

    /**
     * Sprint 29 Sub 11 (E3) — "Dipesan": Logistik ordered approved bought
     * lines (PEMBELIAN / CUSTOM) nothing arrived on yet — when, by whom,
     * from which vendor and when they should arrive. Marking again only
     * moves the expected date / vendor / note. The Pengawas hears it.
     *
     * @param  list<int>  $ids
     * @return Collection<int, ProjectMaterial>
     */
    public function markOrdered(array $ids, array $data, User $actor): Collection
    {
        return DB::transaction(function () use ($ids, $data, $actor) {
            $lines = ProjectMaterial::query()
                ->with(['project', 'project.supervisors:id,name', 'requester:id,name', 'material:id,name', 'unit:id,code'])
                ->whereKey($ids)
                ->lockForUpdate()
                ->get();

            if ($lines->count() !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['ids' => 'Sebagian bahan tidak ditemukan.']);
            }

            foreach ($lines as $line) {
                if ($line->request_status !== MaterialRequestStatus::Disetujui || ! $line->source->isPurchased()) {
                    throw ValidationException::withMessages([
                        'ids' => "{$line->display_name} belum disetujui atau diambil dari gudang — tidak dipesan.",
                    ]);
                }

                if (Quantity::toHundredths($line->qty_received) > 0) {
                    throw ValidationException::withMessages(['ids' => "{$line->display_name} sudah mulai diterima — catat sisanya lewat penerimaan."]);
                }

                $before = $line->only(['ordered_at', 'ordered_by', 'vendor_id', 'expected_at', 'order_note']);
                $note = trim((string) ($data['note'] ?? ''));

                $line->forceFill([
                    'ordered_at' => $line->ordered_at ?? now(),
                    'ordered_by' => $line->ordered_by ?? $actor->id,
                    'vendor_id' => $data['vendor_id'] ?? $line->vendor_id,
                    'expected_at' => $data['expected_at'],
                    'order_note' => $note !== '' ? $note : $line->order_note,
                ])->save();

                $this->audit('logistics.material_ordered', $line, $before, [], $actor);
            }

            $expected = Carbon::parse($data['expected_at'])->format('d/m/Y');
            $lines->groupBy('project_id')->each(fn (Collection $rows) => $this->notifyPlanners(
                $rows->first()->project,
                $rows,
                NotificationType::MaterialOrdered,
                'Bahan Dipesan',
                "Logistik memesan {$rows->count()} bahan proyek \"{$rows->first()->project->name}\" (perkiraan tiba {$expected}): {$this->listText($rows)}.",
            ));

            return $lines;
        });
    }

    public function updatePlan(ProjectMaterial $line, array $data): ProjectMaterial
    {
        $line->update([
            'qty_planned' => $data['qty_planned'],
            ...($line->source->isPurchased() && array_key_exists('vendor_id', $data) ? ['vendor_id' => $data['vendor_id']] : []),
        ]);

        // E1 — a draft catalog line's source follows its amount (the server decides).
        if ($line->request_status === MaterialRequestStatus::Direncanakan && $line->material) {
            $line->update(['source' => $this->sourceFor($line->material, $line->qty_planned)->value]);
        }

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

        $wasComplete = $line->orderStatus()->isComplete();
        $movement = $this->stockService->stockOut($line->material, $line->project, $data, $actor, $line);

        $this->notifyIfArrived($line->fresh(), $wasComplete);

        return $movement;
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
            $wasComplete = $line->orderStatus()->isComplete();

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

            $this->notifyIfArrived($line, $wasComplete);

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

    /** E1 — enough in the warehouse for the whole amount → GUDANG; otherwise it has to be bought. */
    private function sourceFor(Material $material, float|int|string|null $qty): ProjectMaterialSource
    {
        return Quantity::toHundredths($material->stock) >= Quantity::toHundredths($qty)
            ? ProjectMaterialSource::Gudang
            : ProjectMaterialSource::Pembelian;
    }

    /**
     * Rencana Bahan lines `$ids` under a lock, every one at `$expected`.
     *
     * @param  list<int>  $ids
     * @return Collection<int, ProjectMaterial>
     */
    private function lockPlanLines(array $ids, MaterialRequestStatus $expected, string $field, string $already): Collection
    {
        $lines = ProjectMaterial::query()
            ->with(['project', 'project.supervisors:id,name', 'requester:id,name', 'material:id,name', 'unit:id,code'])
            ->whereKey($ids)
            ->fromPlan()
            ->lockForUpdate()
            ->get();

        if ($lines->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages([$field => 'Sebagian bahan tidak ditemukan di rencana bahan proyek.']);
        }

        if ($stale = $lines->first(fn (ProjectMaterial $line) => $line->request_status !== $expected)) {
            throw ValidationException::withMessages([$field => "{$stale->display_name} {$already} — muat ulang halaman."]);
        }

        return $lines;
    }

    /** E4 — the Pengawas hears when a line has fully arrived (bought or from the warehouse). */
    private function notifyIfArrived(ProjectMaterial $line, bool $wasComplete): void
    {
        if ($wasComplete || ! $line->orderStatus()->isComplete() || Quantity::toHundredths($line->qty_planned) <= 0) {
            return;
        }

        $line->loadMissing(['project', 'project.supervisors:id,name', 'requester:id,name', 'unit:id,code', 'material:id,name']);

        $this->notifyPlanners($line->project, collect([$line]), NotificationType::MaterialReceived, 'Bahan Sudah Lengkap',
            "{$line->display_name} ({$line->quantityLabel($line->qty_received)}) sudah lengkap diterima di proyek \"{$line->project->name}\".");
    }

    /** The project's Pengawas, plus whoever planned a Rencana Bahan line (e.g. the Admin Proyek). */
    private function notifyPlanners(Project $project, Collection $lines, NotificationType $type, string $title, string $message): void
    {
        $planners = $lines->filter(fn (ProjectMaterial $line) => $line->request_channel === ProjectMaterial::CHANNEL_RENCANA)->map->requester;

        $this->notificationService->notifyMany(
            [...$project->supervisors->all(), ...$planners->all()],
            $type,
            $title,
            $message,
            ['project_id' => $project->id, 'project_material_ids' => $lines->pluck('id')->all()],
        );
    }

    /** "Triplek 18mm (4 lbr), Lem kuning (2 kg)" */
    private function listText(Collection $lines): string
    {
        return $lines->take(5)->map(fn (ProjectMaterial $line) => "{$line->display_name} ({$line->quantityLabel($line->qty_planned)})")->implode(', ')
            .($lines->count() > 5 ? ', …' : '');
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
