<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\BudgetAllocationLog;
use App\Models\BudgetLine;
use App\Models\BudgetOverrunRequest;
use App\Models\BudgetPost;
use App\Models\BudgetRealization;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\QuotationItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 12 decisions #23–#26 — "Alokasi Dana Proyek" (Model A, like the
 * Excel sheets): once the project's first payment is verified, its PM
 * groups the RAB Fix items into freely named posts so Finance knows where
 * the money goes. Not every item has to be allocated; a post total above
 * the RAB total only warns; the discount is a deduction in the summary,
 * never charged to a post. Every change is logged (BudgetAllocationLog).
 *
 * Not Finance's Sprint 8 FinanceAllocationService (company income split).
 * Who may write is ProjectPolicy::manageBudget(); the rules here.
 */
class ProjectBudgetService
{
    /** Decision #23 — open once any invoice of the project has been verified by Finance. */
    public function isOpen(Project $project): bool
    {
        return Invoice::where('project_id', $project->id)
            ->where('status', InvoiceStatus::Terverifikasi->value)
            ->exists();
    }

    /**
     * The RAB items that can be allocated: the project's RAB Fix. (Sub 12
     * adds the approved addenda.)
     *
     * @return Collection<int, QuotationItem>
     */
    public function sourceItems(Project $project): Collection
    {
        if ($project->quotation_id === null) {
            return collect();
        }

        return QuotationItem::query()
            ->where('quotation_id', $project->quotation_id)
            ->with('section:id,name,sort_order')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Everything the "Alokasi Dana" tab shows.
     *
     * @return array<string, mixed>
     */
    public function overview(Project $project): array
    {
        $posts = $project->budgetPosts()->with([
            'lines.realizations.vendor:id,name',
            'lines.realizations.recorder:id,name',
            // Sprint 12 #28 — the one request per post waiting for the CEO.
            'overrunRequests' => fn ($query) => $query->waiting()->with(['requester:id,name', 'line:id,description']),
        ])->get();
        $allocated = $posts->flatMap->lines->pluck('quotation_item_id')->filter()->all();
        $quotation = $project->quotation;

        $itemsTotal = (float) ($quotation?->items_total ?? 0);
        $discount = (float) ($quotation?->discount_amount ?? 0);
        $rabTotal = (float) ($quotation?->total_amount ?? 0);
        $postsTotal = round((float) $posts->flatMap->lines->sum(fn (BudgetLine $line) => (float) $line->sell_price), 2);
        $unallocated = $this->sourceItems($project)->reject(fn (QuotationItem $item) => in_array($item->id, $allocated, true))->values();

        return [
            'isOpen' => $this->isOpen($project),
            'hasRab' => $quotation !== null,
            'unallocatedItems' => $unallocated->map(fn (QuotationItem $item) => [
                'id' => $item->id,
                'section' => $item->section?->name,
                'description' => $item->description,
                'qty' => (float) $item->qty,
                'unit' => $item->unit?->code,
                'unit_price' => $item->unit_price,
                'total_price' => $item->total_price,
            ])->all(),
            'posts' => $posts->map(function (BudgetPost $post) {
                $total = round((float) $post->lines->sum(fn (BudgetLine $line) => (float) $line->sell_price), 2);
                $realized = round((float) $post->lines->flatMap->realizations->sum(fn (BudgetRealization $row) => (float) $row->total_cost), 2);
                $pending = $post->overrunRequests->first();

                return [
                    'id' => $post->id,
                    'name' => $post->name,
                    'total' => $total,
                    // Sprint 12 #27 — anggaran vs realisasi per pos (+ margin %).
                    'realized' => $realized,
                    'difference' => round($total - $realized, 2),
                    'margin' => $total > 0 ? round(($total - $realized) / $total * 100, 1) : null,
                    'pendingOverrun' => $pending ? [
                        'id' => $pending->id,
                        'item' => $pending->line?->description,
                        'amount_over' => (float) $pending->amount_over,
                        'reason' => $pending->reason,
                        'payload' => $pending->payload,
                        'requested_by' => $pending->requester?->name,
                        'created_at' => $pending->created_at,
                    ] : null,
                    'lines' => $post->lines->map(fn (BudgetLine $line) => [
                        'id' => $line->id,
                        'quotation_item_id' => $line->quotation_item_id,
                        'description' => $line->description,
                        'qty' => (float) $line->qty,
                        'unit' => $line->unit?->code,
                        'unit_price' => $line->unit_price,
                        'sell_price' => $line->sell_price,
                        'realized' => round((float) $line->realizations->sum(fn (BudgetRealization $row) => (float) $row->total_cost), 2),
                        'realized_qty' => round((float) $line->realizations->sum(fn (BudgetRealization $row) => (float) $row->qty_actual), 2),
                        'realizations' => $line->realizations->map(fn (BudgetRealization $row) => [
                            'id' => $row->id,
                            'qty_actual' => (float) $row->qty_actual,
                            'unit_cost' => $row->unit_cost,
                            'total_cost' => $row->total_cost,
                            'vendor' => $row->vendor?->name,
                            'note' => $row->note,
                            'recorded_by' => $row->recorder?->name,
                            'recorded_at' => $row->recorded_at,
                            'reverses_id' => $row->reverses_id,
                            'is_reversed' => $line->realizations->contains('reverses_id', $row->id),
                            'via_overrun' => $row->overrun_request_id !== null,
                        ])->all(),
                    ])->all(),
                ];
            })->all(),
            'summary' => [
                'itemsTotal' => $itemsTotal,
                'discount' => $discount,
                // Pembulatan: what the rounded total adds (or takes) after the discount.
                'rounding' => round($rabTotal - ($itemsTotal - $discount), 2),
                'rabTotal' => $rabTotal,
                'postsTotal' => $postsTotal,
                'unallocatedTotal' => round((float) $unallocated->sum(fn (QuotationItem $item) => (float) $item->total_price), 2),
                // Decision #24 — warns, never blocks.
                'overRab' => $postsTotal > $rabTotal,
                'realizedTotal' => round((float) $posts->flatMap->lines->flatMap->realizations->sum(fn (BudgetRealization $row) => (float) $row->total_cost), 2),
            ],
            'logs' => BudgetAllocationLog::query()
                ->where('project_id', $project->id)
                ->with('user:id,name')
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (BudgetAllocationLog $log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'before' => $log->before,
                    'after' => $log->after,
                    'user_name' => $log->user?->name,
                    'created_at' => $log->created_at,
                ])
                ->all(),
        ];
    }

    public function createPost(Project $project, string $name, User $actor): BudgetPost
    {
        return $this->write($project, $actor, function (Project $project) use ($name, $actor) {
            $name = $this->uniqueName($project, $name);

            $post = $project->budgetPosts()->create([
                'name' => $name,
                'sort_order' => (int) $project->budgetPosts()->max('sort_order') + 1,
                'created_by' => $actor->id,
            ]);

            $this->log($project, $actor, BudgetAllocationLog::ACTION_POST_CREATED, null, ['post' => $name]);

            return $post;
        });
    }

    public function renamePost(BudgetPost $post, string $name, User $actor): BudgetPost
    {
        return $this->write($post->project, $actor, function (Project $project) use ($post, $name, $actor) {
            $before = $post->name;
            $name = $this->uniqueName($project, $name, $post);

            if ($name !== $before) {
                $post->update(['name' => $name]);
                $this->log($project, $actor, BudgetAllocationLog::ACTION_POST_RENAMED, ['post' => $before], ['post' => $name]);
            }

            return $post;
        });
    }

    /** @param  list<int>  $postIds  every post of the project, in the new order */
    public function reorderPosts(Project $project, array $postIds, User $actor): void
    {
        $this->write($project, $actor, function (Project $project) use ($postIds, $actor) {
            $posts = $project->budgetPosts()->get();
            $postIds = array_map('intval', $postIds);

            if (count($postIds) !== $posts->count() || array_diff($posts->pluck('id')->all(), $postIds) !== []) {
                throw ValidationException::withMessages(['post_ids' => 'Urutan harus memuat semua pos proyek ini.']);
            }

            $before = $posts->pluck('name')->all();

            foreach ($postIds as $index => $id) {
                $posts->firstWhere('id', $id)->update(['sort_order' => $index + 1]);
            }

            $after = $project->budgetPosts()->pluck('name')->all();

            if ($before !== $after) {
                $this->log($project, $actor, BudgetAllocationLog::ACTION_POSTS_REORDERED, ['order' => $before], ['order' => $after]);
            }
        });
    }

    /** Decision #24 — only an empty post can be removed (move its items out first). */
    public function deletePost(BudgetPost $post, User $actor): void
    {
        $this->write($post->project, $actor, function (Project $project) use ($post, $actor) {
            if ($post->lines()->exists()) {
                throw ValidationException::withMessages(['post' => "Pos \"{$post->name}\" masih berisi item — pindahkan dulu itemnya."]);
            }

            $name = $post->name;
            $post->delete();

            $this->log($project, $actor, BudgetAllocationLog::ACTION_POST_DELETED, ['post' => $name], null);
        });
    }

    /**
     * Put RAB items into a post — unallocated ones are added, ones in
     * another post are moved (one item, one post).
     *
     * @param  list<int>  $itemIds
     */
    public function allocate(BudgetPost $post, array $itemIds, User $actor): void
    {
        $this->write($post->project, $actor, function (Project $project) use ($post, $itemIds, $actor) {
            $items = $this->itemsOf($project, $itemIds);
            $existing = BudgetLine::whereIn('quotation_item_id', $items->pluck('id'))->with('post:id,name')->get()->keyBy('quotation_item_id');
            $this->ensureMovable($existing->reject(fn (BudgetLine $line) => (int) $line->budget_post_id === (int) $post->id));
            $sort = (int) $post->lines()->max('sort_order');
            $moves = [];

            foreach ($items as $item) {
                $line = $existing->get($item->id);

                if ($line && (int) $line->budget_post_id === (int) $post->id) {
                    continue;
                }

                $moves[] = ['item' => $item->description, 'from' => $line?->post?->name];

                if ($line) {
                    $line->update(['budget_post_id' => $post->id, 'sort_order' => ++$sort]);
                } else {
                    $post->lines()->create([
                        'quotation_item_id' => $item->id,
                        'description' => $item->description,
                        'qty' => $item->qty,
                        'unit_id' => $item->unit_id,
                        'unit_price' => $item->unit_price,
                        'sell_price' => $item->total_price,
                        'sort_order' => ++$sort,
                    ]);
                }
            }

            if ($moves !== []) {
                $this->log($project, $actor, BudgetAllocationLog::ACTION_ITEMS_ALLOCATED, [
                    'items' => array_map(fn (array $move) => ['item' => $move['item'], 'post' => $move['from']], $moves),
                ], [
                    'post' => $post->name,
                    'items' => array_column($moves, 'item'),
                ]);
            }
        });
    }

    /**
     * Take RAB items out of their posts (back to "belum dialokasikan").
     *
     * @param  list<int>  $itemIds
     */
    public function unallocate(Project $project, array $itemIds, User $actor): void
    {
        $this->write($project, $actor, function (Project $project) use ($itemIds, $actor) {
            $items = $this->itemsOf($project, $itemIds);
            $lines = BudgetLine::whereIn('quotation_item_id', $items->pluck('id'))->with('post:id,name')->get();

            if ($lines->isEmpty()) {
                return;
            }

            $this->ensureMovable($lines);

            $before = $lines->map(fn (BudgetLine $line) => ['item' => $line->description, 'post' => $line->post?->name])->values()->all();
            BudgetLine::whereKey($lines->pluck('id'))->delete();

            $this->log($project, $actor, BudgetAllocationLog::ACTION_ITEMS_UNALLOCATED, ['items' => $before], null);
        });
    }

    /**
     * One locked transaction per change: the project row serialises
     * concurrent edits; closed projects and a project whose first payment
     * isn't verified yet are refused.
     *
     * @template T
     *
     * @param  callable(Project): T  $change
     * @return T
     */
    private function write(Project $project, User $actor, callable $change): mixed
    {
        return DB::transaction(function () use ($project, $change) {
            $project = Project::lockForUpdate()->findOrFail($project->id);
            $this->ensureWritable($project);

            return $change($project);
        });
    }

    /** Allocation and realisations change only once the first payment is verified, never on a closed project. */
    public function ensureWritable(Project $project): void
    {
        if (! $this->isOpen($project)) {
            throw ValidationException::withMessages(['post' => 'Alokasi dibuka setelah pembayaran pertama diverifikasi Finance.']);
        }

        if ($project->isClosed()) {
            throw ValidationException::withMessages(['post' => 'Proyek sudah ditutup — alokasi tidak bisa diubah.']);
        }
    }

    /**
     * Sprint 12 #27–#28 — an item with realisations (or a realisation
     * waiting for the CEO) is pinned to its post: moving it would shift
     * money between posts behind the budget check.
     *
     * @param  Collection<int, BudgetLine>  $lines
     */
    private function ensureMovable(Collection $lines): void
    {
        $pinned = $lines->filter(fn (BudgetLine $line) => $line->realizations()->exists()
            || BudgetOverrunRequest::where('budget_line_id', $line->id)->waiting()->exists());

        if ($pinned->isNotEmpty()) {
            throw ValidationException::withMessages([
                'item_ids' => 'Item sudah punya realisasi — tidak bisa dipindah atau dikeluarkan: '.$pinned->pluck('description')->join(', ').'.',
            ]);
        }
    }

    /**
     * @param  list<int>  $itemIds
     * @return Collection<int, QuotationItem>
     */
    private function itemsOf(Project $project, array $itemIds): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $itemIds)));
        $items = $this->sourceItems($project)->whereIn('id', $ids)->values();

        if ($ids === [] || $items->count() !== count($ids)) {
            throw ValidationException::withMessages(['item_ids' => 'Item harus berasal dari RAB proyek ini.']);
        }

        return $items;
    }

    private function uniqueName(Project $project, string $name, ?BudgetPost $except = null): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));

        $taken = $project->budgetPosts()
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->get(['name'])
            ->contains(fn (BudgetPost $post) => mb_strtolower($post->name) === mb_strtolower($name));

        if ($taken) {
            throw ValidationException::withMessages(['name' => "Pos \"{$name}\" sudah ada."]);
        }

        return $name;
    }

    private function log(Project $project, User $actor, string $action, ?array $before, ?array $after): void
    {
        BudgetAllocationLog::create([
            'project_id' => $project->id,
            'user_id' => $actor->id,
            'action' => $action,
            'before' => $before,
            'after' => $after,
        ]);
    }
}
