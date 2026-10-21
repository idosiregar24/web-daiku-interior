<?php

namespace App\Services;

use App\Enums\QuotationStatus;
use App\Models\Project;
use App\Models\ProjectWorkItem;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\SupervisorFee;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 29 Sub 08 (B2/B3) — "Upah Tukang per item RAB" (File A). The one
 * place of the wage formulas:
 *
 *   subtotal upah_i = satuan upah_i × volume_i
 *   total upah      = Σ subtotal upah_i
 *   bobot_i         = subtotal RAB_i ÷ Σ subtotal RAB (items with a wage) × 100
 *
 * The total is the project's "Nilai Upah Tukang": it is written into
 * `supervisor_fees.total_amount` on every change, so the DP / payment flow
 * of Sprint 24 (SupervisorPaymentService, append-only) runs on it unchanged —
 * and never goes below what was already asked or paid.
 */
class WorkItemService
{
    /** Default DP of the Upah Tukang (B4) — the PM may change the amount when deciding. */
    public const DEFAULT_DP_PERCENT = 10;

    public function __construct(private AuditLogService $auditLogService) {}

    /**
     * One work item per RAB item of the project's approved RABs (RAB Proyek +
     * RAB Tambahan the client approved): at Buka Proyek, when a RAB Tambahan
     * is approved, and once for older projects (daiku:work-items). The
     * Kepala Tim defaults to the project's. Idempotent.
     */
    public function ensureForProject(Project $project): int
    {
        $itemIds = $this->approvedQuotations($project)->flatMap(fn (Quotation $quotation) => $quotation->items()->pluck('id'));
        $existing = ProjectWorkItem::where('project_id', $project->id)->pluck('quotation_item_id');
        $missing = $itemIds->diff($existing)->values();

        $missing->each(fn (int $itemId) => ProjectWorkItem::create([
            'project_id' => $project->id,
            'quotation_item_id' => $itemId,
            'team_lead_id' => $project->team_lead_id,
            'team_lead_name' => $project->team_lead_id ? null : $project->team_lead_name,
        ]));

        return $missing->count();
    }

    public function hasWorkItems(Project $project): bool
    {
        return ProjectWorkItem::where('project_id', $project->id)->exists();
    }

    /**
     * The "Upah Tukang" card: the items per bagian RAB (like File A) with
     * their RAB price, wage, subtotals, bobot and Kepala Tim, plus totals.
     *
     * @return array{sections: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function overview(Project $project): array
    {
        $items = $this->items($project);
        $rabOfPaid = $items->filter(fn (ProjectWorkItem $item) => $item->hasWage())->sum(fn (ProjectWorkItem $item) => $this->cents($item->quotationItem->total_price));
        $quotations = $this->approvedQuotations($project)->load('sections:id,quotation_id,name,sort_order')->keyBy('id');

        $sections = $items
            ->groupBy(fn (ProjectWorkItem $item) => $item->quotationItem->quotation_id.'-'.($item->quotationItem->section_id ?? 0))
            ->map(function (Collection $rows, string $key) use ($quotations, $rabOfPaid) {
                $first = $rows->first()->quotationItem;
                $quotation = $quotations->get($first->quotation_id);
                $section = $quotation?->sections->firstWhere('id', $first->section_id);

                return [
                    'id' => $key,
                    'title' => collect([$quotation?->title(), $section?->name])->filter()->implode(' · ') ?: 'Umum',
                    'sort' => [$first->quotation_id, $section?->sort_order ?? -1],
                    'items' => $rows->map(fn (ProjectWorkItem $item) => $this->row($item, $rabOfPaid))->values()->all(),
                ];
            })
            ->sortBy('sort')
            ->map(fn (array $section) => collect($section)->except('sort')->all())
            ->values()
            ->all();

        $paid = $items->filter(fn (ProjectWorkItem $item) => $item->hasWage());

        return [
            'sections' => $sections,
            'summary' => [
                'total_wage' => $this->money($paid->sum(fn (ProjectWorkItem $item) => $this->subtotalWageCents($item))),
                'items' => $items->count(),
                'paid_items' => $paid->count(),
                'unpaid_items' => $items->count() - $paid->count(),
                'per_team_lead' => $paid
                    ->groupBy(fn (ProjectWorkItem $item) => $item->teamLeadLabel() ?? '—')
                    ->map(fn (Collection $rows, string $label) => [
                        'label' => $label,
                        'total' => $this->money($rows->sum(fn (ProjectWorkItem $item) => $this->subtotalWageCents($item))),
                        'items' => $rows->count(),
                    ])
                    ->sortByDesc('total')
                    ->values()
                    ->all(),
            ],
        ];
    }

    /** Total Upah Tukang of the project, Rupiah. */
    public function totalWage(Project $project): float
    {
        return $this->money($this->items($project)->filter(fn (ProjectWorkItem $item) => $item->hasWage())->sum(fn (ProjectWorkItem $item) => $this->subtotalWageCents($item)));
    }

    /**
     * The Pengawas / Admin Proyek sets an item's Satuan Upah and/or Kepala
     * Tim (B3). Before Start Produksi everything may change; afterwards only
     * an item that has no wage yet (a RAB Tambahan item) — the DP was a share
     * of the total.
     *
     * @param  array{unit_wage?: numeric-string|int|float|null, team_lead_id?: int|string|null, team_lead_name?: string|null}  $data
     */
    public function update(ProjectWorkItem $item, array $data, User $actor): ProjectWorkItem
    {
        return DB::transaction(function () use ($item, $data, $actor) {
            $project = Project::lockForUpdate()->findOrFail($item->project_id);
            $item = ProjectWorkItem::lockForUpdate()->findOrFail($item->id);
            $before = $item->only(['unit_wage', 'team_lead_id', 'team_lead_name']);

            if (array_key_exists('unit_wage', $data)) {
                $wage = filled($data['unit_wage']) ? round((float) $data['unit_wage'], 2) : null;

                if ($project->currentPhase()->isProducing() && $item->hasWage() && (float) $item->unit_wage !== (float) $wage) {
                    throw ValidationException::withMessages(['unit_wage' => 'Upah item ini dikunci setelah Start Produksi.']);
                }

                $item->unit_wage = $wage;
            }

            if (array_key_exists('team_lead_id', $data) || array_key_exists('team_lead_name', $data)) {
                $leadId = filled($data['team_lead_id'] ?? null) ? (int) $data['team_lead_id'] : null;
                $item->team_lead_id = $leadId;
                $item->team_lead_name = $leadId === null && filled($data['team_lead_name'] ?? null) ? trim($data['team_lead_name']) : null;
            }

            if (! $item->isDirty()) {
                return $item;
            }

            $item->set_by = $actor->id;
            $item->save();
            $this->syncFee($project, $actor);
            $this->auditLogService->record('work_item.updated', $item, $before, $item->only(['unit_wage', 'team_lead_id', 'team_lead_name']), $actor);

            return $item->fresh(['quotationItem', 'teamLead:id,name']);
        });
    }

    /**
     * "Terapkan ke bagian ini" — one Kepala Tim for several items at once.
     *
     * @param  list<int>  $itemIds
     */
    public function assignTeamLead(Project $project, array $itemIds, ?int $teamLeadId, ?string $teamLeadName, User $actor): int
    {
        return DB::transaction(function () use ($project, $itemIds, $teamLeadId, $teamLeadName, $actor) {
            $items = ProjectWorkItem::query()->where('project_id', $project->id)->whereIn('id', $itemIds)->lockForUpdate()->get();
            $name = $teamLeadId === null && filled($teamLeadName) ? trim($teamLeadName) : null;

            $items->each(fn (ProjectWorkItem $item) => $item->update(['team_lead_id' => $teamLeadId, 'team_lead_name' => $name, 'set_by' => $actor->id]));
            $this->auditLogService->record('work_item.team_lead_assigned', $project, null, [
                'items' => $items->pluck('id')->all(),
                'team_lead_id' => $teamLeadId,
                'team_lead_name' => $name,
            ], $actor);

            return $items->count();
        });
    }

    /**
     * Writes the total into the project's fee row (the Sprint 24 payment flow
     * reads it). Refused below what is already asked / approved / paid.
     */
    public function syncFee(Project $project, ?User $actor): ?SupervisorFee
    {
        $total = $this->totalWage($project);
        $fee = SupervisorFee::where('project_id', $project->id)->lockForUpdate()->first();

        if (! $fee && $total <= 0) {
            return null;
        }

        if ($fee && $total < $fee->committedAmount()) {
            throw ValidationException::withMessages([
                'unit_wage' => 'Total upah tidak boleh di bawah yang sudah diajukan / dibayar (Rp '.number_format($fee->committedAmount(), 0, ',', '.').').',
            ]);
        }

        return SupervisorFee::updateOrCreate(['project_id' => $project->id], [
            'supervisor_id' => $fee?->supervisor_id ?? $project->supervisors()->value('users.id'),
            'total_amount' => $total,
            'dp_percent' => $fee?->dp_percent ?? self::DEFAULT_DP_PERCENT,
            'set_by' => $actor?->id ?? $fee?->set_by,
        ]);
    }

    /** @return Collection<int, ProjectWorkItem> */
    private function items(Project $project): Collection
    {
        return ProjectWorkItem::query()
            ->where('project_id', $project->id)
            ->with(['quotationItem', 'teamLead:id,name'])
            ->get()
            ->sortBy(fn (ProjectWorkItem $item) => [$item->quotationItem->quotation_id, $item->quotationItem->sort_order, $item->quotationItem->id])
            ->values();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Quotation> */
    private function approvedQuotations(Project $project): \Illuminate\Database\Eloquent\Collection
    {
        $addenda = $project->addenda()->where('status', QuotationStatus::ClientApproved->value)->get();

        return \Illuminate\Database\Eloquent\Collection::make(array_filter([$project->quotation()->first()]))->concat($addenda);
    }

    /** @return array<string, mixed> */
    private function row(ProjectWorkItem $item, int $rabOfPaidCents): array
    {
        /** @var QuotationItem $line */
        $line = $item->quotationItem;
        $rabCents = $this->cents($line->total_price);

        return [
            'id' => $item->id,
            'description' => $line->description,
            'volume' => (float) $line->qty,
            'volume_label' => Quantity::format($line->qty),
            'unit' => $line->unit?->code,
            'unit_price' => (float) $line->unit_price,
            'rab_subtotal' => $this->money($rabCents),
            'unit_wage' => $item->unit_wage === null ? null : (float) $item->unit_wage,
            'wage_subtotal' => $this->money($this->subtotalWageCents($item)),
            // Bobot only for items with a wage (File B/C).
            'weight' => $item->hasWage() && $rabOfPaidCents > 0 ? round($rabCents / $rabOfPaidCents * 100, 2) : null,
            'team_lead_id' => $item->team_lead_id,
            'team_lead_name' => $item->team_lead_name,
            'team_lead' => $item->teamLeadLabel(),
        ];
    }

    /** satuan upah × volume, in cents (volume in hundredths — App\Support\Quantity). */
    private function subtotalWageCents(ProjectWorkItem $item): int
    {
        if (! $item->hasWage()) {
            return 0;
        }

        return (int) round($this->cents($item->unit_wage) * Quantity::toHundredths($item->quotationItem->qty) / 100);
    }

    private function cents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private function money(int|float $cents): float
    {
        return round($cents / 100, 2);
    }
}
