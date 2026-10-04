<?php

namespace App\Models;

use App\Support\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PRD §4.8 "Material Master" + "Margin Tracker". `stock` only ever
 * changes through StockService (which also writes the StockMovement
 * ledger row) — it is deliberately not fillable. `cost_price` is the
 * "harga gudang" Logistics sets: what a project is charged per unit
 * taken from the warehouse (Sprint 11 decision #5).
 */
class Material extends Model
{
    use HasFactory;

    /**
     * `name` is the display name MaterialCatalogService assembles from the
     * structured identity (Sprint 11 §5.5); `code`, `match_key` and the
     * merge/duplicate flags are written only by that service.
     */
    protected $fillable = [
        'material_category_id',
        'name',
        'base_name',
        'spec',
        'brand',
        'unit_id',
        'cost_price',
        'sell_price',
        'min_stock',
    ];

    /** Computed, not stored (see the materials migration's docblock). */
    protected $appends = ['margin', 'margin_percent', 'is_low_stock'];

    /** Internal anti-duplicate hashes — never needed by a page. */
    protected $hidden = ['match_key', 'duplicate_key'];

    /** Every display of a material shows its unit (Master Satuan, Sprint 11). */
    protected $with = ['unit:id,code,name'];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'sell_price' => 'decimal:2',
            // Fractional quantities allowed (Sprint 11 decision #3).
            'stock' => 'float',
            'min_stock' => 'float',
            'possible_duplicate' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MaterialCategory::class, 'material_category_id');
    }

    /** Lapis 6 — the item this one was merged into (it stays as an inactive record). */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'merged_into_id');
    }

    /** Usable catalog items — merged ones are inactive. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** PRD §4.8 "Margin Tracker: Harga Jual - Modal" (CSV: sellPrice - costPrice). */
    protected function margin(): Attribute
    {
        return Attribute::get(fn () => round((float) $this->sell_price - (float) $this->cost_price, 2));
    }

    /** Margin as a percentage of the sell price — null when there's no sell price to divide by. */
    protected function marginPercent(): Attribute
    {
        return Attribute::get(fn () => (float) $this->sell_price > 0
            ? round($this->margin / (float) $this->sell_price * 100, 1)
            : null);
    }

    /** CSV Sprint 5: "badge merah jika < min_stock". */
    protected function isLowStock(): Attribute
    {
        return Attribute::get(fn () => $this->stock < $this->min_stock);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function projectMaterials(): HasMany
    {
        return $this->hasMany(ProjectMaterial::class);
    }

    /** "2,5 lbr" — for validation and notification messages. */
    public function quantityLabel(float|int|string $qty): string
    {
        return Quantity::format($qty).' '.($this->unit?->code ?? '');
    }

    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereColumn('stock', '<', 'min_stock');
    }

    public function scopeByCategory(Builder $query, ?int $categoryId): Builder
    {
        return $query->when($categoryId, fn (Builder $q) => $q->where('material_category_id', $categoryId));
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, fn (Builder $q) => $q->where(fn (Builder $q) => $q
            ->where('name', 'like', '%'.$term.'%')
            ->orWhere('code', 'like', '%'.$term.'%')));
    }
}
