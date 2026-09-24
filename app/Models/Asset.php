<?php

namespace App\Models;

use App\Enums\AssetCondition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** PRD §4.8 "Aset Inventaris" — alat, mesin, kendaraan, dengan kondisi dan lokasi. */
class Asset extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category',
        'purchase_date',
        'value',
        'condition',
        'location',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'condition' => AssetCondition::class,
            'purchase_date' => 'date',
            'value' => 'decimal:2',
        ];
    }

    public function scopeByCondition(Builder $query, ?string $condition): Builder
    {
        return $query->when($condition, fn (Builder $q) => $q->where('condition', $condition));
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
            ->where('name', 'like', '%'.$term.'%')
            ->orWhere('location', 'like', '%'.$term.'%')));
    }
}
