<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Sprint 11 Sub 1 — Master Satuan. No conversion between units
 * (decision #4); quantities may be fractional (decision #3).
 */
class Unit extends Model
{
    use HasFactory;

    /**
     * The common units seeded by UnitSeeder — code => name, in dropdown
     * order. The legacy-text backfill migration maps old free text onto
     * these same codes.
     */
    public const DEFAULTS = [
        'pcs' => 'Pieces',
        'unit' => 'Unit',
        'set' => 'Set',
        'dus' => 'Dus',
        'lbr' => 'Lembar',
        'btg' => 'Batang',
        'm' => 'Meter',
        'm2' => 'Meter Persegi',
        'm/lari' => 'Meter Lari',
        'kg' => 'Kilogram',
        'sak' => 'Sak',
        'titik' => 'Titik',
        'ls' => 'Lumpsum',
    ];

    protected $fillable = ['code', 'name', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    public function quotationItems(): HasMany
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function projectMaterials(): HasMany
    {
        return $this->hasMany(ProjectMaterial::class);
    }

    /** Referenced anywhere → may only be deactivated, never deleted. */
    public function isInUse(): bool
    {
        return $this->materials()->exists()
            || $this->quotationItems()->exists()
            || $this->projectMaterials()->exists();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /** Active units for a dropdown — the shape every form's `units` prop uses. */
    public static function options(): Collection
    {
        return static::query()->active()->ordered()->get(['id', 'code', 'name']);
    }
}
