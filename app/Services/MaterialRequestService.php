<?php

namespace App\Services;

use App\Enums\MaterialRequestStatus;
use App\Enums\ProjectMaterialSource;
use App\Enums\ProjectStatus;
use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 11 Sub 4 — items outside the catalog only enter a project
 * through a request that Logistics decides (decision #13), plus the
 * Tukang path where the project's PM approves first (Sprint 12 #31):
 *
 *   PM / Estimator ── ajukan ──────────────────────────────▶ DIAJUKAN
 *   Tukang ── ajukan ──▶ MENUNGGU_PM ── PM setujui ─────────▶ DIAJUKAN
 *                                    └─ PM tolak ─▶ DITOLAK
 *   DIAJUKAN ── Logistik: pakai katalog / daftarkan katalog / custom ─▶ DISETUJUI
 *            └─ Logistik: tolak (alasan) ─────────────────────────────▶ DITOLAK
 *
 * A request is a project_materials row: it waits as a CUSTOM placeholder
 * (no catalog item, possibly no unit for a Tukang) and Logistics' decision
 * turns it into a GUDANG / PEMBELIAN / CUSTOM line. The requester's
 * original input stays in `requested_snapshot`, so what was asked and what
 * was approved are both visible. Nothing can be bought or used before
 * DISETUJUI (ProjectMaterialService::lockApproved()).
 */
class MaterialRequestService
{
    public function __construct(
        private NotificationService $notificationService,
        private AuditLogService $auditLogService,
        private MaterialCatalogService $catalogService,
        private ProjectService $projectService,
    ) {}

    /**
     * PM/Estimator send the full request straight to Logistics; a Tukang
     * names the item, qty and a note, and their PM sees it first.
     */
    public function submit(Project $project, array $data, User $actor): ProjectMaterial
    {
        if (! in_array($project->status, [ProjectStatus::Active, ProjectStatus::OnHold], true)) {
            throw ValidationException::withMessages([
                'name' => 'Pengajuan barang hanya untuk proyek yang masih berjalan.',
            ]);
        }

        $fromTukang = $actor->hasRole('FIELD_STAFF') && ! $actor->hasAnyRole(['PM', 'ESTIMATOR', 'SUPERADMIN']);

        $snapshot = array_filter([
            'name' => trim($data['name']),
            'spec' => $data['spec'] ?? null,
            'unit_id' => $data['unit_id'] ?? null,
            'qty' => (float) $data['qty'],
            'estimated_price' => isset($data['estimated_price']) ? (float) $data['estimated_price'] : null,
            'reason' => $data['reason'] ?? null,
            'vendor_id' => $data['vendor_id'] ?? null,
            'photo_link' => $data['photo_link'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        return DB::transaction(function () use ($project, $data, $actor, $fromTukang, $snapshot) {
            $line = ProjectMaterial::create([
                'project_id' => $project->id,
                'source' => ProjectMaterialSource::Custom->value,
                'material_id' => null,
                'custom_name' => $snapshot['name'],
                'custom_spec' => $data['spec'] ?? null,
                'unit_id' => $data['unit_id'] ?? null,
                'unit_price' => $data['estimated_price'] ?? null,
                'vendor_id' => $data['vendor_id'] ?? null,
                'qty_planned' => $data['qty'],
                'request_status' => ($fromTukang ? MaterialRequestStatus::MenungguPm : MaterialRequestStatus::Diajukan)->value,
                'request_channel' => $fromTukang ? ProjectMaterial::CHANNEL_TUKANG : ProjectMaterial::CHANNEL_TIM,
                'submitted_at' => $fromTukang ? null : now(),
                'request_reason' => $data['reason'] ?? null,
                'photo_link' => $data['photo_link'] ?? null,
                'requested_by' => $actor->id,
                'requested_snapshot' => $snapshot,
            ]);

            $this->auditLogService->record('logistics.material_requested', $line, null, [
                'project_id' => $project->id,
                'channel' => $line->request_channel,
                ...$snapshot,
            ], $actor);

            $what = "{$actor->name} mengajukan {$snapshot['name']} ({$this->qtyText($line)}) untuk proyek \"{$project->name}\"";

            if ($fromTukang) {
                // Sprint 12 #22/#31 — the PM and the Asisten PM of the project may approve.
                $this->notificationService->notifyMany(
                    [$project->pm, $project->assistantPm],
                    'material_request_pm_pending',
                    'Pengajuan Barang dari Tukang',
                    "{$what}. Setujui atau tolak sebelum diteruskan ke Logistik.",
                    ['project_id' => $project->id, 'project_material_id' => $line->id],
                );
            } else {
                $this->notifyLogistics($line, $what.'.');
            }

            return $line;
        });
    }

    /** The project's PM approves (→ Logistics) or rejects (reason required) a Tukang's request. */
    public function pmDecide(ProjectMaterial $line, string $decision, ?string $reason, User $actor): ProjectMaterial
    {
        return DB::transaction(function () use ($line, $decision, $reason, $actor) {
            $line = $this->lockWithStatus($line, MaterialRequestStatus::MenungguPm, 'Pengajuan ini tidak sedang menunggu persetujuan PM.');
            $approve = $decision === 'approve';

            if (! $approve && blank($reason)) {
                throw ValidationException::withMessages(['reason' => 'Alasan penolakan wajib diisi.']);
            }

            $line->forceFill([
                'request_status' => ($approve ? MaterialRequestStatus::Diajukan : MaterialRequestStatus::Ditolak)->value,
                'pm_reviewed_by' => $actor->id,
                'pm_reviewed_at' => now(),
                'submitted_at' => $approve ? now() : null,
                'reject_reason' => $approve ? null : trim($reason),
            ])->save();

            $this->auditLogService->record(
                $approve ? 'logistics.material_request_pm_approved' : 'logistics.material_request_pm_rejected',
                $line,
                ['request_status' => MaterialRequestStatus::MenungguPm->value],
                ['request_status' => $line->request_status->value, 'reason' => $line->reject_reason],
                $actor,
            );

            if ($approve) {
                $this->notifyLogistics($line, "Pengajuan {$line->custom_name} ({$this->qtyText($line)}) dari Tukang {$line->requester?->name} untuk proyek \"{$line->project->name}\" disetujui PM {$actor->name}.");
            } else {
                $this->notifyRequester($line, 'Pengajuan Barang Ditolak PM', "Pengajuan {$line->custom_name} untuk proyek \"{$line->project->name}\" ditolak PM: {$line->reject_reason}");
                $this->projectService->completeIfFinished($line->project);
            }

            return $line;
        });
    }

    /**
     * Logistics' decision (decision #13). Logistics may change qty, price
     * and spec while approving — the requester's original input stays in
     * `requested_snapshot`. Decisions:
     *
     * - PAKAI_KATALOG: an existing catalog item, taken from stock (GUDANG)
     *   or bought for the project (PEMBELIAN).
     * - DAFTAR_KATALOG: a new catalog item is registered (with its
     *   warehouse price) and bought for the project (PEMBELIAN).
     * - CUSTOM: a one-off item for this project only; never enters the catalog.
     * - TOLAK: refused, reason required.
     */
    public function review(ProjectMaterial $line, array $data, User $actor): ProjectMaterial
    {
        return DB::transaction(function () use ($line, $data, $actor) {
            $line = $this->lockWithStatus($line, MaterialRequestStatus::Diajukan, 'Pengajuan ini tidak sedang menunggu tinjauan Logistik.');
            $decision = $data['decision'];
            $before = $line->only(['request_status', 'source', 'material_id', 'custom_name', 'custom_spec', 'unit_id', 'qty_planned', 'unit_price', 'vendor_id']);

            $common = [
                'request_status' => MaterialRequestStatus::Disetujui->value,
                'review_decision' => $decision,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ];

            match ($decision) {
                ProjectMaterial::DECISION_TOLAK => $line->forceFill([
                    ...$common,
                    'request_status' => MaterialRequestStatus::Ditolak->value,
                    'reject_reason' => trim((string) ($data['reject_reason'] ?? '')) ?: throw ValidationException::withMessages([
                        'reject_reason' => 'Alasan penolakan wajib diisi.',
                    ]),
                ]),
                ProjectMaterial::DECISION_PAKAI_KATALOG => $line->forceFill([
                    ...$common,
                    ...$this->catalogLine(Material::findOrFail($data['material_id']), $data),
                ]),
                ProjectMaterial::DECISION_DAFTAR_KATALOG => $line->forceFill([
                    ...$common,
                    // Same anti-duplicate rules as any new catalog item (§5.5).
                    ...$this->catalogLine($this->catalogService->create([
                        'material_category_id' => $data['material_category_id'],
                        'base_name' => $data['name'],
                        'spec' => $data['spec'] ?? null,
                        'brand' => $data['brand'] ?? null,
                        'unit_id' => $data['unit_id'],
                        'cost_price' => $data['warehouse_price'],
                        'sell_price' => $data['sell_price'] ?? null,
                        'similar_reason' => $data['similar_reason'] ?? null,
                    ], $actor, '', 'name'), [...$data, 'source' => ProjectMaterialSource::Pembelian->value]),
                ]),
                ProjectMaterial::DECISION_CUSTOM => $line->forceFill([
                    ...$common,
                    'source' => ProjectMaterialSource::Custom->value,
                    'material_id' => null,
                    'custom_name' => trim($data['name']),
                    'custom_spec' => $data['spec'] ?? null,
                    'unit_id' => $data['unit_id'],
                    'qty_planned' => $data['qty'],
                    'unit_price' => $data['unit_price'] ?? null,
                    'vendor_id' => $data['vendor_id'] ?? null,
                ]),
            };

            $line->save();
            $approved = $decision !== ProjectMaterial::DECISION_TOLAK;

            $this->auditLogService->record(
                $approved ? 'logistics.material_request_approved' : 'logistics.material_request_rejected',
                $line,
                $before,
                [
                    ...$line->only(array_keys($before)),
                    'decision' => $decision,
                    'reject_reason' => $line->reject_reason,
                    'requested' => $line->requested_snapshot,
                ],
                $actor,
            );

            $line->load(['material:id,name,unit_id', 'unit:id,code']);
            $this->notifyRequester(
                $line,
                $approved ? 'Pengajuan Barang Disetujui' : 'Pengajuan Barang Ditolak',
                $approved
                    ? "Pengajuan {$line->requested_snapshot['name']} untuk proyek \"{$line->project->name}\" disetujui Logistik sebagai "
                        ."{$line->display_name} ({$this->qtyText($line)}, {$this->decisionText($decision, $line)})."
                    : "Pengajuan {$line->custom_name} untuk proyek \"{$line->project->name}\" ditolak Logistik: {$line->reject_reason}",
            );

            if (! $approved) {
                $this->projectService->completeIfFinished($line->project);
            }

            return $line;
        });
    }

    /**
     * Daily reminder (decision #13): requests waiting more than one working
     * day — DIAJUKAN at Logistics, MENUNGGU_PM at a PM — get one combined
     * notification per recipient, and the CEO a summary. `reminded_at`
     * makes a re-run on the same day a no-op. Returns how many requests
     * were included.
     */
    public function sendReminders(?Carbon $now = null): int
    {
        $now ??= now('Asia/Jakarta');
        // "1 hari kerja": one working day back, skipping Sunday (Senin–Sabtu, PRD §6.5).
        $cutoff = $now->copy()->subDay();
        while ($cutoff->isSunday()) {
            $cutoff->subDay();
        }

        $due = ProjectMaterial::query()
            ->with(['project:id,name,pm_id,assistant_pm_id', 'project.pm:id,name,is_active', 'project.assistantPm:id,name,is_active', 'requester:id,name'])
            ->pendingRequest()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('request_status', MaterialRequestStatus::Diajukan->value)->where('submitted_at', '<=', $cutoff))
                ->orWhere(fn ($q) => $q->where('request_status', MaterialRequestStatus::MenungguPm->value)->where('created_at', '<=', $cutoff)))
            ->where(fn ($q) => $q->whereNull('reminded_at')->orWhere('reminded_at', '<', $now->copy()->startOfDay()))
            ->orderBy('id')
            ->get();

        if ($due->isEmpty()) {
            return 0;
        }

        [$atLogistics, $atPm] = $due->partition(fn (ProjectMaterial $line) => $line->request_status === MaterialRequestStatus::Diajukan);

        if ($atLogistics->isNotEmpty()) {
            $this->notificationService->notifyRoles(
                ['LOGISTICS'],
                'material_request_reminder',
                'Pengajuan Barang Belum Ditinjau',
                $atLogistics->count().' pengajuan barang menunggu tinjauan Logistik lebih dari 1 hari kerja: '.$this->listText($atLogistics).'.',
                ['project_material_ids' => $atLogistics->pluck('id')->all()],
            );
        }

        $atPm->groupBy(fn (ProjectMaterial $line) => $line->project->pm_id.'-'.$line->project->assistant_pm_id)->each(function (Collection $lines) {
            $this->notificationService->notifyMany(
                [$lines->first()->project->pm, $lines->first()->project->assistantPm],
                'material_request_reminder',
                'Pengajuan Tukang Menunggu Anda',
                $lines->count().' pengajuan barang dari Tukang menunggu persetujuan Anda lebih dari 1 hari kerja: '.$this->listText($lines).'.',
                ['project_material_ids' => $lines->pluck('id')->all()],
            );
        });

        $this->notificationService->notifyRoles(
            ['CEO'],
            'material_request_summary',
            'Ringkasan Pengajuan Barang Tertunda',
            "{$due->count()} pengajuan barang tertunda lebih dari 1 hari kerja — {$atLogistics->count()} di Logistik, {$atPm->count()} di PM.",
            ['project_material_ids' => $due->pluck('id')->all()],
        );

        ProjectMaterial::whereKey($due->pluck('id'))->update(['reminded_at' => $now]);

        return $due->count();
    }

    /**
     * Catalog items similar to the request — shown on Logistics' review
     * screen so an existing item gets used instead of a duplicate (§5.5
     * Lapis 3/5) — MaterialCatalogService's matching, synonyms included.
     *
     * @return Collection<int, Material>
     */
    public function similarCatalog(ProjectMaterial $line, int $limit = 5): Collection
    {
        return $this->catalogService->findSimilar([
            'base_name' => $line->custom_name,
            'spec' => $line->custom_spec,
            'unit_id' => $line->unit_id,
        ], limit: $limit);
    }

    /**
     * Requests for similar items on other projects and what Logistics
     * decided — replaces a "custom used in ≥ 3 projects" flag (decision #13).
     *
     * @return Collection<int, ProjectMaterial>
     */
    public function similarRequests(ProjectMaterial $line, int $limit = 5): Collection
    {
        $words = $this->keywords($line->custom_name);

        if ($words === []) {
            return collect();
        }

        return ProjectMaterial::query()
            ->with(['project:id,name', 'material:id,name,unit_id', 'unit:id,code'])
            ->requested()
            ->whereKeyNot($line->id)
            ->where('project_id', '!=', $line->project_id)
            ->where(fn ($q) => collect($words)->each(fn ($word) => $q
                ->orWhere('custom_name', 'like', '%'.$word.'%')
                ->orWhere('requested_snapshot->name', 'like', '%'.$word.'%')))
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /** @return list<string> */
    private function keywords(?string $name): array
    {
        return collect(preg_split('/[^\pL\pN]+/u', mb_strtolower((string) $name)) ?: [])
            ->filter(fn ($word) => mb_strlen($word) >= 3)
            ->unique()
            ->take(5)
            ->values()
            ->all();
    }

    /** The catalog side of an approved line: item, source, its unit, qty, price and vendor. */
    private function catalogLine(Material $material, array $data): array
    {
        $source = ProjectMaterialSource::from($data['source']);

        return [
            'source' => $source->value,
            'material_id' => $material->id,
            'unit_id' => $material->unit_id,
            'qty_planned' => $data['qty'],
            'unit_price' => $source === ProjectMaterialSource::Pembelian ? ($data['unit_price'] ?? null) : null,
            'vendor_id' => $source === ProjectMaterialSource::Pembelian ? ($data['vendor_id'] ?? null) : null,
        ];
    }

    private function lockWithStatus(ProjectMaterial $line, MaterialRequestStatus $expected, string $message): ProjectMaterial
    {
        $locked = ProjectMaterial::query()
            // The whole project: completeIfFinished() may update and sync it.
            ->with(['project', 'project.pm:id,name,is_active', 'requester:id,name,is_active', 'unit:id,code'])
            ->lockForUpdate()
            ->findOrFail($line->id);

        if ($locked->request_status !== $expected) {
            throw ValidationException::withMessages(['decision' => $message]);
        }

        return $locked;
    }

    private function notifyLogistics(ProjectMaterial $line, string $message): void
    {
        $this->notificationService->notifyRoles(
            ['LOGISTICS'],
            'material_request_submitted',
            'Pengajuan Barang Baru',
            $message.' Tinjau di menu Pengajuan Barang.',
            ['project_id' => $line->project_id, 'project_material_id' => $line->id],
        );
    }

    /** The requester and — when someone else asked — the project's PM. */
    private function notifyRequester(ProjectMaterial $line, string $title, string $message): void
    {
        $this->notificationService->notifyMany(
            [$line->requester, $line->project->pm],
            'material_request_decided',
            $title,
            $message,
            ['project_id' => $line->project_id, 'project_material_id' => $line->id],
        );
    }

    private function qtyText(ProjectMaterial $line): string
    {
        return trim(Quantity::format($line->qty_planned).' '.($line->unit?->code ?? ''));
    }

    private function decisionText(string $decision, ProjectMaterial $line): string
    {
        return match ($decision) {
            ProjectMaterial::DECISION_PAKAI_KATALOG => $line->source === ProjectMaterialSource::Gudang ? 'diambil dari gudang' : 'barang katalog, dibeli untuk proyek',
            ProjectMaterial::DECISION_DAFTAR_KATALOG => 'didaftarkan ke katalog, dibeli untuk proyek',
            default => 'barang custom proyek',
        };
    }

    /** "Kaca 8mm — Proyek A, Handle custom — Proyek B" */
    private function listText(Collection $lines): string
    {
        return $lines->take(5)->map(fn (ProjectMaterial $line) => "{$line->display_name} — {$line->project->name}")->implode(', ')
            .($lines->count() > 5 ? ', …' : '');
    }
}
