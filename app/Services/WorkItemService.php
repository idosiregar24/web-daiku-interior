<?php

namespace App\Services;

use App\Enums\QuotationStatus;
use App\Models\Project;
use App\Models\ProjectWorkItem;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\SupervisorFee;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WorkItemProgress;
use App\Models\WorkItemProgressDraft;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
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
     * Sprint 33 — the Upah Tukang table's "Simpan": several Satuan Upah at
     * once, each through update() (same lock after Start Produksi, same
     * audit); all or nothing. An error names the row (`items.N.unit_wage`).
     *
     * @param  list<array{id: int|string, unit_wage: numeric-string|int|float|null}>  $rows
     */
    public function saveWages(Project $project, array $rows, User $actor): int
    {
        return DB::transaction(function () use ($project, $rows, $actor) {
            $items = ProjectWorkItem::query()->where('project_id', $project->id)->whereIn('id', collect($rows)->pluck('id'))->get()->keyBy('id');
            $changed = 0;

            foreach (array_values($rows) as $index => $row) {
                $item = $items->get((int) $row['id']);

                if (! $item) {
                    throw ValidationException::withMessages(["items.{$index}.unit_wage" => 'Item ini bukan item RAB proyek ini.']);
                }

                $before = $item->unit_wage === null ? null : (float) $item->unit_wage;

                try {
                    $saved = $this->update($item, ['unit_wage' => $row['unit_wage']], $actor);
                } catch (ValidationException $error) {
                    throw ValidationException::withMessages(["items.{$index}.unit_wage" => $error->errors()['unit_wage'][0] ?? 'Upah tidak bisa disimpan.']);
                }

                $changed += ($saved->unit_wage === null ? null : (float) $saved->unit_wage) !== $before ? 1 : 0;
            }

            return $changed;
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

    // ── Sprint 29 Sub 09 — pengecekan % per item & pencairan (File B/C) ──────
    //
    //   progres_i        = Σ % pengecekan item i (0–100)
    //   progres berbobot = Σ (bobot_i × progres_i) ÷ 100     (bobot from the RAB value)
    //   upah boleh cair  = Σ (subtotal upah_i × progres_i ÷ 100)
    //   sudah cair       = DP + earlier payments asked / approved / paid (not rejected)
    //   cair kali ini    = upah boleh cair − sudah cair (never below 0, never above the rest of the total)
    //
    // Percentages are summed in hundredths (70,25 % = 7025), money in cents.

    /** Label of the items without a Kepala Tim in the split per Kepala Tim. */
    public const NO_TEAM_LEAD = 'Tanpa Kepala Tim';

    /**
     * Progress per work item, % (Σ of its pengecekan rows).
     *
     * @return array<int, float>
     */
    public function progress(Project $project): array
    {
        return array_map(fn (int $hundredths) => (float) ($hundredths / 100), $this->progressHundredths($project));
    }

    /**
     * Progres berbobot of the project (File B "Persentase Vendor" / File C), %;
     * null without items with a wage. One query — the same aggregate the
     * lists use (withWeightedSums()), so the detail page, the 70 % check and
     * every list show the same number.
     */
    public function weightedProgress(Project $project): ?float
    {
        $sums = Project::query()->whereKey($project->id)->select('projects.id')->tap(fn (Builder $query) => self::withWeightedSums($query))->toBase()->first();

        return $sums ? self::weightedFromSums($sums->work_rab_total, $sums->work_rab_progress) : null;
    }

    /**
     * Sprint 29 Sub 10 (B7) — the progres berbobot of many projects in the
     * query that lists them (no query per row): adds
     *
     *   work_rab_total    = Σ subtotal RAB_i                (items with a wage)
     *   work_rab_progress = Σ subtotal RAB_i × progres_i    (progres_i = Σ % of its pengecekan rows)
     *
     * so that Σ (bobot_i × progres_i) ÷ 100 = work_rab_progress ÷ work_rab_total
     * (bobot_i = subtotal RAB_i ÷ Σ subtotal RAB × 100) — read back with
     * weightedFromSums(). `work_rab_total` is NULL without items with a wage.
     *
     * @param  Builder<Project>  $query
     */
    public static function withWeightedSums(Builder $query): void
    {
        $query->addSelect([
            'work_rab_total' => ProjectWorkItem::query()
                ->join('quotation_items', 'quotation_items.id', '=', 'project_work_items.quotation_item_id')
                ->whereColumn('project_work_items.project_id', 'projects.id')
                ->where('project_work_items.unit_wage', '>', 0)
                ->selectRaw('SUM(quotation_items.total_price)'),
            'work_rab_progress' => WorkItemProgress::query()
                ->join('project_work_items', 'project_work_items.id', '=', 'work_item_progress.project_work_item_id')
                ->join('quotation_items', 'quotation_items.id', '=', 'project_work_items.quotation_item_id')
                ->whereColumn('work_item_progress.project_id', 'projects.id')
                ->where('project_work_items.unit_wage', '>', 0)
                ->selectRaw('SUM(quotation_items.total_price * work_item_progress.percent)'),
        ]);
    }

    /** Progres berbobot (%, 2 decimals) from the sums of withWeightedSums(); null without items with a wage. */
    public static function weightedFromSums(mixed $rabTotal, mixed $rabProgress): ?float
    {
        if ($rabTotal === null) {
            return null;
        }

        return (float) $rabTotal > 0 ? round((float) $rabProgress / (float) $rabTotal, 2) : 0.0;
    }

    /** Upah boleh cair — Σ (subtotal upah_i × progres_i ÷ 100), Rupiah. */
    public function payableWage(Project $project): float
    {
        return $this->figures($project)['payable'];
    }

    /** Sudah cair — DP + earlier payments asked, approved or paid (rejected ones don't count). */
    public function alreadyPaid(Project $project, ?int $exceptPaymentId = null): float
    {
        return SupervisorFee::where('project_id', $project->id)->first()?->committedAmount($exceptPaymentId) ?? 0.0;
    }

    /** Every item with a wage is at 100 % (and there is at least one) — the pelunasan is due. */
    public function allDone(Project $project): bool
    {
        return $this->figures($project)['all_done'];
    }

    /**
     * The pengecekan numbers of the project as they stand.
     *
     * @return array{weighted_progress: float|null, payable: float, total_wage: float, all_done: bool, teams: list<array{label: string, total_wage: float, progress: float|null, payable: float}>}
     */
    public function figures(Project $project): array
    {
        $items = $this->items($project)->filter(fn (ProjectWorkItem $item) => $item->hasWage())->values();
        $progress = $this->progressHundredths($project);

        return [
            ...$this->sums($items, $progress),
            'all_done' => $items->isNotEmpty() && $items->every(fn (ProjectWorkItem $item) => ($progress[$item->id] ?? 0) >= 10000),
            'teams' => $items
                ->groupBy(fn (ProjectWorkItem $item) => $item->teamLeadLabel() ?? self::NO_TEAM_LEAD)
                ->map(function (Collection $rows, string $label) use ($progress) {
                    $sums = $this->sums($rows, $progress);

                    return ['label' => $label, 'total_wage' => $sums['total_wage'], 'progress' => $sums['weighted_progress'], 'payable' => $sums['payable']];
                })
                ->sortByDesc('total_wage')
                ->values()
                ->all(),
        ];
    }

    /**
     * Cair kali ini — what a pengecekan asks now: upah boleh cair − sudah
     * cair, never below 0 and never above what is left of the total.
     */
    public function payableNow(Project $project): float
    {
        $fee = SupervisorFee::where('project_id', $project->id)->first();

        if (! $fee) {
            return 0.0;
        }

        $committed = $fee->committedAmount();

        return max(0.0, min(round($this->payableWage($project) - $committed, 2), round((float) $fee->total_amount - $committed, 2)));
    }

    /**
     * Sprint 29 Sub 09 (B6, simple — no per-person split) — how an amount
     * splits per Kepala Tim. `total`: pro rata to each team's total wage (DP).
     * `progress`: what each team may draw now — its upah boleh cair minus its
     * share of what was already drawn (taken pro rata to the team's total, as
     * the DP was) — so the split of "cair kali ini" follows who worked.
     * Whole rupiah; the rounding rest goes to the largest share.
     *
     * @param  'total'|'progress'  $basis
     * @return list<array{label: string, amount: float}>
     */
    public function teamShares(Project $project, float $amount, string $basis = 'progress', float $alreadyPaid = 0.0): array
    {
        $teams = collect($this->figures($project)['teams']);
        $total = $teams->sum('total_wage');

        if ($teams->isEmpty() || $total <= 0 || $amount <= 0) {
            return [];
        }

        $weights = $teams->mapWithKeys(fn (array $team) => [$team['label'] => $basis === 'progress'
            ? max(0.0, $team['payable'] - $alreadyPaid * $team['total_wage'] / $total)
            : $team['total_wage']]);

        if ($weights->sum() <= 0) {
            $weights = $teams->mapWithKeys(fn (array $team) => [$team['label'] => $team['total_wage']]);
        }

        return $this->distribute($amount, $weights->all());
    }

    /**
     * The PM changed the amount of a payment: its split per Kepala Tim is
     * scaled to the new amount (same proportions).
     *
     * @param  list<array{label: string, amount: float|int}>|null  $breakdown
     * @return list<array{label: string, amount: float}>|null
     */
    public function scaleShares(?array $breakdown, float $amount): ?array
    {
        if (! $breakdown) {
            return $breakdown;
        }

        return $this->distribute($amount, collect($breakdown)->mapWithKeys(fn (array $row) => [$row['label'] => (float) $row['amount']])->all());
    }

    /**
     * "Kirim pengecekan" — append the % added per item (File B "CEK n").
     * Only items with a wage of this project; Σ per item stays 0–100; a
     * negative row (correction) needs a note. Runs inside the caller's
     * transaction (the project row locked). Returns the rows written.
     *
     * @param  list<array{item_id: int|string, percent: numeric-string|int|float, note?: string|null}>  $entries
     */
    public function recordProgress(Project $project, array $entries, ?WeeklyReport $report, User $actor): int
    {
        $items = ProjectWorkItem::query()->where('project_id', $project->id)->with('quotationItem:id,description')->get()->keyBy('id');
        $progress = $this->progressHundredths($project);
        $seen = [];
        $rows = [];

        foreach (array_values($entries) as $index => $entry) {
            $hundredths = (int) round(((float) ($entry['percent'] ?? 0)) * 100);
            $itemId = (int) ($entry['item_id'] ?? 0);

            if ($hundredths === 0) {
                continue;
            }

            /** @var ProjectWorkItem|null $item */
            $item = $items->get($itemId);

            if (! $item || ! $item->hasWage() || isset($seen[$itemId])) {
                throw ValidationException::withMessages(["progress.{$index}.percent" => 'Item ini bukan item berupah proyek ini.']);
            }

            $seen[$itemId] = true;
            $before = $progress[$itemId] ?? 0;
            $after = $before + $hundredths;
            $note = trim((string) ($entry['note'] ?? ''));

            if ($after < 0 || $after > 10000) {
                throw ValidationException::withMessages(["progress.{$index}.percent" => sprintf(
                    'Progres "%s" jadi %s %% — harus 0–100 %% (sisa %s %%).',
                    $item->quotationItem->description,
                    Quantity::format($after / 100),
                    Quantity::format((10000 - $before) / 100),
                )]);
            }

            if ($hundredths < 0 && $note === '') {
                throw ValidationException::withMessages(["progress.{$index}.note" => 'Koreksi (persen minus) wajib diberi catatan.']);
            }

            $rows[] = [
                'project_id' => $project->id,
                'project_work_item_id' => $itemId,
                'weekly_report_id' => $report?->id,
                'checked_on' => now('Asia/Jakarta')->toDateString(),
                'percent' => $hundredths / 100,
                'note' => $note !== '' ? $note : null,
                'recorded_by' => $actor->id,
            ];
        }

        foreach ($rows as $row) {
            WorkItemProgress::create($row);
        }

        if ($rows !== []) {
            $this->auditLogService->record('work_item.progress_recorded', $report ?? $project, null, [
                'items' => collect($rows)->map(fn (array $row) => ['item_id' => $row['project_work_item_id'], 'percent' => $row['percent'], 'note' => $row['note']])->all(),
            ], $actor);
        }

        return count($rows);
    }

    /**
     * The draft of the next pengecekan — the Pengawas or the Admin Proyek
     * (saved automatically from the form). Unknown items are dropped; the
     * limits are checked when the Pengawas sends it.
     *
     * @param  array{progress?: ?array<int, array{item_id: int|string, percent?: numeric-string|int|float|null, note?: string|null}>, notes?: ?string, links?: ?array<int, string>}  $data
     */
    public function saveDraft(Project $project, array $data, User $actor): WorkItemProgressDraft
    {
        $itemIds = ProjectWorkItem::where('project_id', $project->id)->pluck('id')->flip();

        $entries = collect($data['progress'] ?? [])
            ->filter(fn (array $entry) => $itemIds->has((int) $entry['item_id']))
            ->mapWithKeys(fn (array $entry) => [(int) $entry['item_id'] => [
                'percent' => filled($entry['percent'] ?? null) ? round((float) $entry['percent'], 2) : null,
                'note' => filled($entry['note'] ?? null) ? trim($entry['note']) : null,
            ]])
            ->reject(fn (array $entry) => $entry['percent'] === null && $entry['note'] === null)
            ->all();

        return WorkItemProgressDraft::updateOrCreate(['project_id' => $project->id], [
            'entries' => $entries,
            'notes' => filled($data['notes'] ?? null) ? $data['notes'] : null,
            'links' => collect($data['links'] ?? [])->map(fn ($url) => trim((string) $url))->filter()->values()->all(),
            'updated_by' => $actor->id,
        ]);
    }

    public function clearDraft(Project $project): void
    {
        WorkItemProgressDraft::where('project_id', $project->id)->delete();
    }

    /**
     * The pengecekan form of the Eksekusi tab: the items with a wage
     * (Uraian · Kepala Tim · progres sebelumnya · sisa), the numbers as they
     * stand, and the draft. Money already drawn only with `$withPayments`
     * (CEO / PM / Finance / the Pengawas) — the Admin Proyek fills the %.
     *
     * @return array<string, mixed>
     */
    public function checkOverview(Project $project, bool $withPayments): array
    {
        $items = $this->items($project)->filter(fn (ProjectWorkItem $item) => $item->hasWage())->values();
        $progress = $this->progressHundredths($project);
        $rabOfPaid = $items->sum(fn (ProjectWorkItem $item) => $this->cents($item->quotationItem->total_price));
        $figures = $this->figures($project);
        $fee = $withPayments ? SupervisorFee::where('project_id', $project->id)->first() : null;
        $committed = $withPayments ? ($fee?->committedAmount() ?? 0.0) : null;
        $draft = WorkItemProgressDraft::with('updater:id,name')->where('project_id', $project->id)->first();

        return [
            'items' => $items->map(fn (ProjectWorkItem $item) => [
                'id' => $item->id,
                'description' => $item->quotationItem->description,
                'volume_label' => trim(Quantity::format($item->quotationItem->qty).' '.($item->quotationItem->unit?->code ?? '')),
                'team_lead' => $item->teamLeadLabel() ?? self::NO_TEAM_LEAD,
                'wage_subtotal' => $this->money($this->subtotalWageCents($item)),
                // Exact share of the RAB value (the form's live preview sums it).
                'rab_subtotal' => $this->money($this->cents($item->quotationItem->total_price)),
                'weight' => $rabOfPaid > 0 ? round($this->cents($item->quotationItem->total_price) / $rabOfPaid * 100, 2) : 0.0,
                'progress' => (float) (($progress[$item->id] ?? 0) / 100),
            ])->all(),
            'summary' => [
                'total_wage' => $figures['total_wage'],
                'weighted_progress' => $figures['weighted_progress'],
                'payable' => $figures['payable'],
                'already_paid' => $committed,
                // Same rule as payableNow().
                'payable_now' => $withPayments
                    ? ($fee ? max(0.0, min(round($figures['payable'] - $committed, 2), round((float) $fee->total_amount - $committed, 2))) : 0.0)
                    : null,
                'all_done' => $figures['all_done'],
                'teams' => $figures['teams'],
            ],
            'draft' => $draft ? [
                'entries' => (object) ($draft->entries ?? []),
                'notes' => $draft->notes,
                'links' => $draft->links ?? [],
                'updated_by' => $draft->updater?->name,
                'updated_at' => $draft->updated_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * Sprint 31 — "Rekap Progres per Item": File C (SIMULASI PERSENTASI) as
     * a table. One row per item with a wage — Bobot · % added on each
     * pengecekan date · Total % (column AU) · Kontribusi = Total × Bobot ÷
     * 100 (column AV) — and per date the progres berbobot it added and the
     * running total; the last running total is the project's (AV24).
     *
     * @return array{dates: list<string>, items: list<array<string, mixed>>, per_date: list<array{date: string, added: float, cumulative: float}>, total: float|null}
     */
    public function progressRecap(Project $project): array
    {
        $items = $this->items($project)->filter(fn (ProjectWorkItem $item) => $item->hasWage())->values();
        $rabCents = $items->mapWithKeys(fn (ProjectWorkItem $item) => [$item->id => $this->cents($item->quotationItem->total_price)]);
        $rabTotal = $rabCents->sum();

        // item id → date → hundredths of a percent (corrections included).
        $cells = [];
        WorkItemProgress::query()
            ->where('project_id', $project->id)
            ->whereIn('project_work_item_id', $items->pluck('id'))
            ->get(['project_work_item_id', 'checked_on', 'percent'])
            ->each(function (WorkItemProgress $row) use (&$cells) {
                $date = $row->checked_on->toDateString();
                $cells[$row->project_work_item_id][$date] = ($cells[$row->project_work_item_id][$date] ?? 0) + (int) round(((float) $row->percent) * 100);
            });

        $dates = collect($cells)->flatMap(fn (array $byDate) => array_keys($byDate))->unique()->sort()->values();
        // Σ RAB cents × hundredths, per date — divided once, so the rounding never piles up.
        $weightedOf = fn (string $date) => $items->sum(fn (ProjectWorkItem $item) => $rabCents[$item->id] * ($cells[$item->id][$date] ?? 0));
        $percentOf = fn (int|float $weighted) => $rabTotal > 0 ? round($weighted / $rabTotal / 100, 2) : 0.0;
        $running = 0;

        return [
            'dates' => $dates->all(),
            'items' => $items->map(function (ProjectWorkItem $item) use ($cells, $rabCents, $rabTotal) {
                $total = array_sum($cells[$item->id] ?? []);

                return [
                    'id' => $item->id,
                    'description' => $item->quotationItem->description,
                    'volume_label' => trim(Quantity::format($item->quotationItem->qty).' '.($item->quotationItem->unit?->code ?? '')),
                    'team_lead' => $item->teamLeadLabel() ?? self::NO_TEAM_LEAD,
                    'weight' => $rabTotal > 0 ? round($rabCents[$item->id] / $rabTotal * 100, 2) : 0.0,
                    'cells' => (object) array_map(fn (int $hundredths) => $hundredths / 100, $cells[$item->id] ?? []),
                    'total' => $total / 100,
                    'contribution' => $rabTotal > 0 ? round($rabCents[$item->id] * $total / $rabTotal / 100, 2) : 0.0,
                ];
            })->all(),
            'per_date' => $dates->map(function (string $date) use ($weightedOf, $percentOf, &$running) {
                $added = $weightedOf($date);
                $running += $added;

                return ['date' => $date, 'added' => $percentOf($added), 'cumulative' => $percentOf($running)];
            })->all(),
            'total' => $items->isEmpty() ? null : $percentOf($running),
        ];
    }

    // ── Sprint 31 Sub 03 — jadwal per item (File C, the light-blue cells) ───

    /** Status of an item against its schedule (the "Jadwal per Item" card, the late queue). */
    public const SCHEDULE_UNSCHEDULED = 'BELUM_DIJADWALKAN';

    public const SCHEDULE_UPCOMING = 'AKAN_DATANG';

    public const SCHEDULE_RUNNING = 'BERJALAN';

    public const SCHEDULE_LATE = 'TERLAMBAT';

    public const SCHEDULE_DONE = 'SELESAI';

    /**
     * The Pengawas / Admin Proyek sets an item's production window and,
     * optionally, its installation window (File C's second block). Inside
     * the project's dates; installation never starts before production.
     * Not locked after Start Produksi — a schedule moves (audited).
     *
     * @param  array{production_start?: ?string, production_end?: ?string, installation_start?: ?string, installation_end?: ?string}  $data
     */
    public function updateSchedule(ProjectWorkItem $item, array $data, User $actor): ProjectWorkItem
    {
        return DB::transaction(function () use ($item, $data, $actor) {
            $project = Project::lockForUpdate()->findOrFail($item->project_id);
            $item = ProjectWorkItem::lockForUpdate()->with('quotationItem:id,description')->findOrFail($item->id);
            $fields = ['production_start', 'production_end', 'installation_start', 'installation_end'];
            $values = collect($fields)->mapWithKeys(fn (string $field) => [$field => filled($data[$field] ?? null) ? $data[$field] : null]);

            foreach ($fields as $field) {
                $date = $values[$field];

                if ($date && $project->start_date && $date < $project->start_date->toDateString()) {
                    throw ValidationException::withMessages([$field => 'Jadwal item tidak boleh sebelum tanggal mulai proyek ('.$project->start_date->translatedFormat('d M Y').').']);
                }

                if ($date && $project->end_date && $date > $project->end_date->toDateString()) {
                    throw ValidationException::withMessages([$field => 'Jadwal item tidak boleh melewati tanggal selesai proyek ('.$project->end_date->translatedFormat('d M Y').').']);
                }
            }

            if ($values['installation_start'] && $values['production_start'] && $values['installation_start'] < $values['production_start']) {
                throw ValidationException::withMessages(['installation_start' => 'Pemasangan tidak boleh mulai sebelum produksi item ini dimulai.']);
            }

            $before = collect($item->only($fields))->map(fn ($date) => $date?->toDateString())->all();
            $item->fill($values->all());

            if (! $item->isDirty($fields)) {
                return $item;
            }

            $item->set_by = $actor->id;
            $item->save();
            $this->auditLogService->record('work_item.scheduled', $item, $before, [...$values->all(), 'item' => $item->quotationItem->description], $actor);

            return $item;
        });
    }

    /**
     * "Jadwalkan berurutan" — File C's staircase in one step: the chosen
     * items (in RAB order) get `production_days` days of production each,
     * the first from `production_start`, every next one `gap_days` later;
     * the installation the same way when `installation_start` is given.
     * Each item goes through updateSchedule() (same checks, audited); one
     * refused item saves none.
     *
     * @param  array{item_ids: list<int|string>, production_start: string, production_days: int|string, gap_days?: int|string|null, installation_start?: ?string, installation_days?: int|string|null}  $data
     */
    public function scheduleSequence(Project $project, array $data, User $actor): int
    {
        $ids = collect($data['item_ids'])->map(fn ($id) => (int) $id)->unique();
        $items = $this->items($project)->filter(fn (ProjectWorkItem $item) => $item->hasWage() && $ids->contains($item->id))->values();

        if ($items->count() !== $ids->count()) {
            throw ValidationException::withMessages(['item_ids' => 'Sebagian item bukan item berupah proyek ini.']);
        }

        $gap = (int) ($data['gap_days'] ?? 1);
        $day = fn (string $start, int $offset) => Carbon::parse($start)->addDays($offset)->toDateString();
        $install = filled($data['installation_start'] ?? null) && (int) ($data['installation_days'] ?? 0) > 0;

        return DB::transaction(function () use ($items, $data, $gap, $day, $install, $actor) {
            foreach ($items as $index => $item) {
                $productionStart = $day($data['production_start'], $index * $gap);
                $installationStart = $install ? $day($data['installation_start'], $index * $gap) : null;

                try {
                    $this->updateSchedule($item, [
                        'production_start' => $productionStart,
                        'production_end' => $day($productionStart, (int) $data['production_days'] - 1),
                        'installation_start' => $installationStart,
                        'installation_end' => $installationStart ? $day($installationStart, (int) $data['installation_days'] - 1) : null,
                    ], $actor);
                } catch (ValidationException $e) {
                    // Name the item: the dates were computed, the user only typed the first one.
                    throw ValidationException::withMessages(['production_start' => "\"{$item->quotationItem->description}\": ".collect($e->errors())->flatten()->first()]);
                }
            }

            return $items->count();
        });
    }

    /**
     * The "Jadwal per Item" card: every item with a wage, its windows, its
     * progress and where it stands against the schedule, plus the range of
     * days the chart spans.
     *
     * @return array<string, mixed>
     */
    public function scheduleOverview(Project $project): array
    {
        $items = $this->items($project)->filter(fn (ProjectWorkItem $item) => $item->hasWage())->values();
        $progress = $this->progressHundredths($project);
        $today = now('Asia/Jakarta')->toDateString();
        $date = fn ($value) => $value?->toDateString();

        $rows = $items->map(fn (ProjectWorkItem $item) => [
            'id' => $item->id,
            'description' => $item->quotationItem->description,
            'volume_label' => trim(Quantity::format($item->quotationItem->qty).' '.($item->quotationItem->unit?->code ?? '')),
            'team_lead' => $item->teamLeadLabel() ?? self::NO_TEAM_LEAD,
            'progress' => (float) (($progress[$item->id] ?? 0) / 100),
            'production_start' => $date($item->production_start),
            'production_end' => $date($item->production_end),
            'installation_start' => $date($item->installation_start),
            'installation_end' => $date($item->installation_end),
            'status' => self::scheduleStatus($item, $progress[$item->id] ?? 0, $today),
        ]);

        $dates = $rows->flatMap(fn (array $row) => [$row['production_start'], $row['production_end'], $row['installation_start'], $row['installation_end']])
            ->push($project->start_date?->toDateString(), $project->end_date?->toDateString())
            ->filter()
            ->sort()
            ->values();

        return [
            'items' => $rows->all(),
            'from' => $dates->first(),
            'to' => $dates->last(),
            'today' => $today,
            'project_start' => $project->start_date?->toDateString(),
            'project_end' => $project->end_date?->toDateString(),
            'summary' => [
                'total' => $rows->count(),
                'late' => $rows->where('status', self::SCHEDULE_LATE)->count(),
                'unscheduled' => $rows->where('status', self::SCHEDULE_UNSCHEDULED)->count(),
            ],
        ];
    }

    /**
     * Done at 100 %; unscheduled without any window; late once the last
     * window (installation, else production) ended below 100 %; upcoming
     * before the first window; running in between.
     */
    public static function scheduleStatus(ProjectWorkItem $item, int $progressHundredths, string $today): string
    {
        $start = ($item->production_start ?? $item->installation_start)?->toDateString();
        $end = ($item->installation_end ?? $item->production_end)?->toDateString();

        return match (true) {
            $progressHundredths >= 10000 => self::SCHEDULE_DONE,
            $start === null && $end === null => self::SCHEDULE_UNSCHEDULED,
            $end !== null && $today > $end => self::SCHEDULE_LATE,
            $start !== null && $today < $start => self::SCHEDULE_UPCOMING,
            default => self::SCHEDULE_RUNNING,
        };
    }

    /** @return array<int, int> work item id → Σ % in hundredths */
    private function progressHundredths(Project $project): array
    {
        return WorkItemProgress::query()
            ->where('project_id', $project->id)
            ->groupBy('project_work_item_id')
            ->selectRaw('project_work_item_id, SUM(percent) as total')
            ->pluck('total', 'project_work_item_id')
            ->map(fn ($total) => (int) round(((float) $total) * 100))
            ->all();
    }

    /**
     * @param  Collection<int, ProjectWorkItem>  $items  items with a wage
     * @param  array<int, int>  $progress  hundredths of a percent per item
     * @return array{weighted_progress: float|null, payable: float, total_wage: float}
     */
    private function sums(Collection $items, array $progress): array
    {
        $rabCents = $items->sum(fn (ProjectWorkItem $item) => $this->cents($item->quotationItem->total_price));
        $weighted = $items->sum(fn (ProjectWorkItem $item) => $this->cents($item->quotationItem->total_price) * ($progress[$item->id] ?? 0));
        $payableCents = $items->sum(fn (ProjectWorkItem $item) => $this->subtotalWageCents($item) * ($progress[$item->id] ?? 0) / 10000);

        return [
            'weighted_progress' => $items->isEmpty() ? null : ($rabCents > 0 ? round($weighted / $rabCents / 100, 2) : 0.0),
            // Whole rupiah, like the DP (SupervisorFee::dpAmount()).
            'payable' => (float) round($payableCents / 100),
            'total_wage' => $this->money($items->sum(fn (ProjectWorkItem $item) => $this->subtotalWageCents($item))),
        ];
    }

    /**
     * Split `$amount` (whole rupiah) by `$weights` — largest remainder, so the parts add up exactly.
     *
     * @param  array<string, float>  $weights
     * @return list<array{label: string, amount: float}>
     */
    private function distribute(float $amount, array $weights): array
    {
        $sum = array_sum($weights);
        $rupiah = (int) round($amount);

        if ($sum <= 0 || $rupiah <= 0) {
            return [];
        }

        $raw = array_map(fn (float $weight) => $rupiah * $weight / $sum, $weights);
        $parts = array_map(fn (float $value) => (int) floor($value), $raw);
        $rest = $rupiah - array_sum($parts);
        $order = $raw;
        uasort($order, fn (float $a, float $b) => ($b - floor($b)) <=> ($a - floor($a)));

        foreach (array_keys($order) as $label) {
            if ($rest-- <= 0) {
                break;
            }
            $parts[$label]++;
        }

        arsort($parts);

        return collect($parts)
            ->filter(fn (int $value) => $value > 0)
            ->map(fn (int $value, string $label) => ['label' => $label, 'amount' => (float) $value])
            ->values()
            ->all();
    }

    /** @return Collection<int, ProjectWorkItem> */
    private function items(Project $project): Collection
    {
        return ProjectWorkItem::query()
            ->where('project_id', $project->id)
            ->with(['quotationItem.unit', 'teamLead:id,name'])
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
