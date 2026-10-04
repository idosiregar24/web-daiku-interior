<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Sprint 11 Sub 2 — Master Vendor. Managed by CEO + SUPERADMIN in Data
 * Master → Vendor; every other role picks an active vendor from
 * VendorSelect. A vendor that's referenced is deactivated, never deleted
 * (VendorService::delete()).
 */
class Vendor extends Model
{
    use HasFactory;

    public const TYPE_MATERIAL = 'MATERIAL';

    public const TYPE_JASA = 'JASA';

    public const TYPES = [self::TYPE_MATERIAL, self::TYPE_JASA];

    protected $fillable = [
        'name',
        'contact',
        'address',
        'type',
        'bank_name',
        'bank_account_number',
        'account_holder',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function supplierDebts(): HasMany
    {
        return $this->hasMany(SupplierDebt::class);
    }

    public function projectMaterials(): HasMany
    {
        return $this->hasMany(ProjectMaterial::class);
    }

    public function isInUse(): bool
    {
        return $this->supplierDebts()->exists() || $this->projectMaterials()->exists();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, fn (Builder $q) => $q->where('name', 'like', '%'.$term.'%'));
    }

    /** Active vendors for VendorSelect — the shape every form's `vendors` prop uses. */
    public static function options(): Collection
    {
        return static::query()->active()->orderBy('name')->get(['id', 'name', 'type']);
    }
}
