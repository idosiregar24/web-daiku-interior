<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Sprint 11 decision #11 — Data Master → Kategori Material (SUPERADMIN).
 * `code_prefix` numbers the catalog items of the category (KYP-0012).
 */
class MaterialCategory extends Model
{
    use HasFactory;

    /** Seeded by MaterialCatalogSeeder — name => code prefix. */
    public const DEFAULTS = [
        'Kayu & Panel' => 'KYP',
        'Finishing' => 'FIN',
        'Hardware' => 'HDW',
        'Kaca & Cermin' => 'KCA',
        'Besi & Aluminium' => 'BSA',
        'Listrik & Lampu' => 'LST',
        'Cat & Pelapis' => 'CAT',
        'Bahan Habis Pakai' => 'BHP',
        'Lain-lain' => 'LLN',
    ];

    protected $fillable = ['name', 'code_prefix', 'is_active', 'sort_order'];

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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /** Active categories for a dropdown. */
    public static function options(): Collection
    {
        return static::query()->active()->ordered()->get(['id', 'name', 'code_prefix']);
    }
}
