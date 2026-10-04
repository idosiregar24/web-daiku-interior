<?php

namespace App\Services;

use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\MaterialSynonym;
use App\Models\ProjectMaterial;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 11 §5.5 — the catalog never holds the same item twice, however
 * it's written ("Triplek 17mm", "triplek 17 mm", "Plywood 17mm"):
 *
 * - Lapis 1: an item is category + base name + spec + brand + unit; the
 *   display name and the code (KYP-0012) are generated, never typed.
 * - Lapis 2: `match_key` = hash of those parts normalized (lower case,
 *   punctuation out, "17 mm" = "17mm", "122 × 244" = "122x244",
 *   synonyms applied, word order ignored). It's UNIQUE in the database,
 *   so two people saving the same item at once still end up with one.
 * - Lapis 3: before saving, similar items are looked up — an exact match
 *   is refused, a similar one needs a reason (audited).
 * - Lapis 5: only Logistics (and SUPERADMIN) reaches create() — routes.
 * - Lapis 6: merge(B → A) moves stock and project lines, B stays as an
 *   inactive record (`merged_into_id`).
 */
class MaterialCatalogService
{
    /** @var array<string, string>|null term => canonical, longest term first */
    private ?array $synonyms = null;

    public function __construct(
        private AuditLogService $auditLogService,
        private StockService $stockService,
    ) {}

    /**
     * Create a catalog item. `$field` prefixes error keys and `$nameField`
     * names the base-name input when the item is born inside another form
     * (a request review, a custom return).
     *
     * @param  array{material_category_id: int, base_name: string, spec?: ?string, brand?: ?string, unit_id: int, cost_price: numeric, sell_price?: ?numeric, min_stock?: ?numeric, similar_reason?: ?string}  $data
     */
    public function create(array $data, User $actor, string $field = '', string $nameField = 'base_name'): Material
    {
        $identity = $this->identity($data);
        $key = $this->matchKey($identity);

        $this->refuseExact($key, null, $field.$nameField);

        $similar = $this->findSimilar($identity);
        $reason = trim((string) ($data['similar_reason'] ?? ''));

        if ($similar->isNotEmpty() && $reason === '') {
            throw ValidationException::withMessages([
                $field.'similar_reason' => 'Barang serupa sudah ada: '.$this->describe($similar)
                    .'. Pakai barang itu, atau isi alasan untuk tetap membuat barang baru.',
            ]);
        }

        try {
            $material = DB::transaction(function () use ($data, $identity, $key) {
                // Serializes code numbering within the category.
                $category = MaterialCategory::query()->lockForUpdate()->findOrFail($identity['material_category_id']);

                $material = new Material([
                    ...$identity,
                    'name' => $this->displayName($identity),
                    'cost_price' => $data['cost_price'],
                    'sell_price' => $data['sell_price'] ?? $data['cost_price'],
                    'min_stock' => $data['min_stock'] ?? 0,
                ]);
                $material->forceFill(['code' => $this->nextCode($category), 'match_key' => $key])->save();

                return $material;
            });
        } catch (UniqueConstraintViolationException) {
            // Someone saved the same item between the check and the insert.
            $this->refuseExact($key, null, $field.$nameField);
            throw ValidationException::withMessages([$field.$nameField => 'Barang ini baru saja didaftarkan orang lain — muat ulang katalog.']);
        }

        $this->auditLogService->record('logistics.material_registered', $material, null, $material->only([
            'code', 'name', 'material_category_id', 'unit_id', 'cost_price', 'sell_price',
        ]), $actor);

        if ($similar->isNotEmpty()) {
            $this->auditLogService->record('logistics.material_created_despite_similar', $material, null, [
                'reason' => $reason,
                'similar' => $similar->map(fn (Material $m) => "{$m->code} {$m->name}")->all(),
            ], $actor);
        }

        return $material;
    }

    /** Edit the identity or prices; the code stays. Only an exact clash with another item is refused. */
    public function update(Material $material, array $data): Material
    {
        $identity = $this->identity($data);
        $key = $this->matchKey($identity);

        $this->refuseExact($key, $material->id, 'base_name');

        try {
            $material->fill([
                ...$identity,
                'name' => $this->displayName($identity),
                'cost_price' => $data['cost_price'],
                'sell_price' => $data['sell_price'] ?? $data['cost_price'],
                'min_stock' => $data['min_stock'] ?? 0,
            ]);
            // A flagged duplicate keeps waiting for a merge rather than grabbing the key.
            $material->forceFill($material->possible_duplicate ? ['duplicate_key' => $key] : ['match_key' => $key])->save();
        } catch (UniqueConstraintViolationException) {
            $this->refuseExact($key, $material->id, 'base_name');
        }

        return $material;
    }

    /**
     * Similar active items (Lapis 3): same base name or one a typo away,
     * or most words in common. Best matches first; an exact match scores
     * highest.
     *
     * @param  array{material_category_id?: ?int, base_name?: ?string, spec?: ?string, brand?: ?string, unit_id?: ?int}  $identity
     * @return Collection<int, Material>
     */
    public function findSimilar(array $identity, ?int $excludeId = null, int $limit = 5): Collection
    {
        $base = $this->normalize($identity['base_name'] ?? '');

        if ($base === '') {
            return collect();
        }

        $words = $this->words(($identity['base_name'] ?? '').' '.($identity['spec'] ?? '').' '.($identity['brand'] ?? ''));
        $spec = $this->part($identity['spec'] ?? '');

        return Material::query()
            ->with(['unit:id,code', 'category:id,name'])
            ->active()
            ->when($excludeId, fn ($q) => $q->whereKeyNot($excludeId))
            ->get(['id', 'code', 'name', 'base_name', 'spec', 'brand', 'material_category_id', 'unit_id', 'stock', 'cost_price'])
            ->map(function (Material $material) use ($base, $words, $spec, $identity) {
                $otherBase = $this->normalize($material->base_name ?? $material->name);
                $otherWords = $this->words("{$material->base_name} {$material->spec} {$material->brand}");
                $shared = count(array_intersect($words, $otherWords));
                $jaccard = $shared / max(count(array_unique([...$words, ...$otherWords])), 1);
                $sameBase = $otherBase === $base
                    || (min(strlen($base), strlen($otherBase)) >= 4 && levenshtein($base, $otherBase) <= 2);

                if (! $sameBase && $jaccard < 0.5) {
                    return null;
                }

                $material->setAttribute('similarity', round(
                    $jaccard
                    + ($sameBase ? 0.3 : 0)
                    + ($spec !== '' && $spec === $this->part($material->spec ?? '') ? 0.2 : 0)
                    + ((int) ($identity['material_category_id'] ?? 0) === (int) $material->material_category_id ? 0.1 : 0),
                    2,
                ));

                return $material;
            })
            ->filter()
            ->sortByDesc('similarity')
            ->take($limit)
            ->values();
    }

    /**
     * Lapis 6 — merge B into A (same unit). B's stock moves to A through
     * the ledger (MERGE_OUT / MERGE_IN), project lines pointing at B now
     * point at A, and B stays as an inactive record (`merged_into_id`) so
     * its old stock history still reads. Audited.
     */
    public function merge(Material $from, Material $into, User $actor): Material
    {
        if ($from->is($into)) {
            throw ValidationException::withMessages(['target_id' => 'Pilih barang tujuan yang berbeda.']);
        }

        return DB::transaction(function () use ($from, $into, $actor) {
            // Lock in id order so two opposite merges can't deadlock.
            $locked = Material::query()->whereKey([$from->id, $into->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $from = $locked[$from->id];
            $into = $locked[$into->id];

            if (! $from->is_active || ! $into->is_active) {
                throw ValidationException::withMessages(['target_id' => 'Barang yang sudah digabung/nonaktif tidak bisa digabung lagi.']);
            }

            if ((int) $from->unit_id !== (int) $into->unit_id) {
                throw ValidationException::withMessages([
                    'target_id' => "Satuan berbeda ({$from->unit?->code} vs {$into->unit?->code}) — barang hanya bisa digabung bila satuannya sama.",
                ]);
            }

            $movedStock = $from->stock;
            if ($movedStock > 0) {
                $this->stockService->transferForMerge($from, $into, $actor);
            }

            $redirected = ProjectMaterial::where('material_id', $from->id)->update(['material_id' => $into->id]);

            $from->forceFill([
                'is_active' => false,
                'merged_into_id' => $into->id,
                'match_key' => null,
                'duplicate_key' => null,
                'possible_duplicate' => false,
            ])->save();

            $this->auditLogService->record('logistics.material_merged', $from, ['code' => $from->code, 'name' => $from->name], [
                'merged_into' => "{$into->code} {$into->name}",
                'merged_into_id' => $into->id,
                'stock_moved' => $movedStock,
                'project_lines_redirected' => $redirected,
            ], $actor);

            // The kept item may have been the flagged one of its pair.
            $this->rebuildMatchKeys();

            return $into->fresh();
        });
    }

    /**
     * Recompute every active item's match_key — after the migration, a
     * synonym change, or a merge. Per key the oldest item keeps it; the
     * others are flagged `possible_duplicate` (never failed, never deleted)
     * for the Cek Duplikat page. Returns how many are flagged.
     */
    public function rebuildMatchKeys(): int
    {
        $this->synonyms = null;

        return DB::transaction(function () {
            $materials = DB::table('materials')->orderBy('id')
                ->get(['id', 'material_category_id', 'base_name', 'name', 'spec', 'brand', 'unit_id', 'is_active']);

            // Clear first, so reassigning never trips the UNIQUE index mid-way.
            DB::table('materials')->update(['match_key' => null]);

            $taken = [];
            $flagged = 0;

            foreach ($materials as $row) {
                if (! $row->is_active) {
                    DB::table('materials')->where('id', $row->id)->update(['duplicate_key' => null, 'possible_duplicate' => false]);

                    continue;
                }

                $key = $this->matchKey([
                    'material_category_id' => $row->material_category_id,
                    'base_name' => $row->base_name ?? $row->name,
                    'spec' => $row->spec,
                    'brand' => $row->brand,
                    'unit_id' => $row->unit_id,
                ]);

                if (isset($taken[$key])) {
                    $flagged++;
                    DB::table('materials')->where('id', $row->id)->update(['duplicate_key' => $key, 'possible_duplicate' => true]);
                } else {
                    $taken[$key] = true;
                    DB::table('materials')->where('id', $row->id)->update(['match_key' => $key, 'duplicate_key' => null, 'possible_duplicate' => false]);
                }
            }

            return $flagged;
        });
    }

    /**
     * Flagged duplicates with the item that holds their key — the Cek
     * Duplikat page's "merge into" pairs.
     *
     * @return Collection<int, array{keeper: Material, duplicates: Collection<int, Material>}>
     */
    public function duplicateGroups(): Collection
    {
        $flagged = Material::query()->with(['unit:id,code', 'category:id,name'])->active()->where('possible_duplicate', true)->get();

        if ($flagged->isEmpty()) {
            return collect();
        }

        $keepers = Material::query()->with(['unit:id,code', 'category:id,name'])
            ->whereIn('match_key', $flagged->pluck('duplicate_key')->unique())
            ->get()
            ->keyBy('match_key');

        return $flagged->groupBy('duplicate_key')
            ->filter(fn ($duplicates, $key) => $keepers->has($key))
            ->map(fn ($duplicates, $key) => ['keeper' => $keepers[$key], 'duplicates' => $duplicates->values()])
            ->values();
    }

    /** "Triplek 17 mm 122×244 — Sengon Super" (the unit is shown separately). */
    public function displayName(array $identity): string
    {
        $name = trim($identity['base_name'].' '.($identity['spec'] ?? ''));

        return filled($identity['brand'] ?? null) ? "{$name} — {$identity['brand']}" : $name;
    }

    /** @param array{material_category_id?: ?int, base_name?: ?string, spec?: ?string, brand?: ?string, unit_id?: ?int} $identity */
    public function matchKey(array $identity): string
    {
        return sha1(implode('|', [
            (int) ($identity['material_category_id'] ?? 0),
            $this->part($identity['base_name'] ?? ''),
            $this->part($identity['spec'] ?? ''),
            $this->part($identity['brand'] ?? ''),
            (int) ($identity['unit_id'] ?? 0),
        ]));
    }

    /**
     * Lower case, "×"/"*" → x, decimal comma → dot, punctuation out,
     * "122 x 244" → "122x244", "17 mm" → "17mm", synonyms applied.
     */
    public function normalize(?string $text): string
    {
        $text = mb_strtolower(trim((string) $text));
        $text = str_replace(['×', '*'], 'x', $text);
        $text = preg_replace('/(\d),(\d)/u', '$1.$2', $text) ?? $text;
        $text = preg_replace('/[^\pL\pN.]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/(?<!\d)\.|\.(?!\d)/u', ' ', $text) ?? $text;
        $text = preg_replace('/(\d)\s*x\s*(\d)/u', '$1x$2', $text) ?? $text;
        $text = preg_replace('/(\d)\s+(?=\pL)/u', '$1', $text) ?? $text;
        $text = ' '.preg_replace('/\s+/u', ' ', trim($text)).' ';

        foreach ($this->synonyms() as $term => $canonical) {
            $text = str_replace(" {$term} ", " {$canonical} ", $text);
        }

        return trim($text);
    }

    /** One key part: normalized words, order ignored. */
    private function part(?string $text): string
    {
        $words = $this->words($text);
        sort($words);

        return implode(' ', $words);
    }

    /** @return list<string> */
    private function words(?string $text): array
    {
        $normalized = $this->normalize($text);

        return $normalized === '' ? [] : array_values(array_unique(explode(' ', $normalized)));
    }

    /** Trimmed, single-spaced identity fields as stored. */
    private function identity(array $data): array
    {
        $clean = fn ($value) => ($value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '')) === '' ? null : $value;

        return [
            'material_category_id' => (int) $data['material_category_id'],
            'base_name' => (string) $clean($data['base_name']),
            'spec' => $clean($data['spec'] ?? null),
            'brand' => $clean($data['brand'] ?? null),
            'unit_id' => (int) $data['unit_id'],
        ];
    }

    private function refuseExact(string $key, ?int $exceptId, string $field): void
    {
        $existing = Material::query()->with('unit:id,code')->where('match_key', $key)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->first();

        if ($existing) {
            throw ValidationException::withMessages([
                $field => "Barang ini sudah ada di katalog: {$existing->code} {$existing->name} ({$existing->unit?->code}). Pakai barang itu.",
            ]);
        }
    }

    private function nextCode(MaterialCategory $category): string
    {
        $last = Material::query()
            ->where('code', 'like', $category->code_prefix.'-%')
            ->pluck('code')
            ->map(fn (string $code) => (int) substr($code, strlen($category->code_prefix) + 1))
            ->max() ?? 0;

        return sprintf('%s-%04d', $category->code_prefix, $last + 1);
    }

    private function describe(Collection $materials): string
    {
        return $materials->map(fn (Material $m) => trim("{$m->code} {$m->name} ({$m->unit?->code})"))->implode(', ');
    }

    /** @return array<string, string> */
    private function synonyms(): array
    {
        if ($this->synonyms === null) {
            $this->synonyms = MaterialSynonym::query()->pluck('canonical', 'term')->all();
            uksort($this->synonyms, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        }

        return $this->synonyms;
    }
}
