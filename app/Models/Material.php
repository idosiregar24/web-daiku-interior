<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PRD §4.8 "Material Master" + "Margin Tracker". `stock` only ever
 * changes through StockService (which also writes the StockMovement
 * ledger row) — it is deliberately not fillable.
 */
class Material extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'unit',
        'cost_price',
        'sell_price',
        'min_stock',
        'category',
    ];

    /** Computed, not stored (see the materials migration's docblock). */
    protected $appends = ['margin', 'margin_percent', 'is_low_stock'];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'sell_price' => 'decimal:2',
            'stock' => 'integer',
            'min_stock' => 'integer',
        ];
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

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function projectMaterials(): HasMany
    {
        return $this->hasMany(ProjectMaterial::class);
    }

    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereColumn('stock', '<', 'min_stock');
    }

    public function scopeByCategory(Builder $query, ?string $category): Builder
    {
        return $query->when($category, fn (Builder $q) => $q->where('category', $category));
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, fn (Builder $q) => $q->where('name', 'like', '%'.$term.'%'));
    }
}
