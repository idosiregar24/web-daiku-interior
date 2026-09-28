<?php

namespace App\Models;

use App\Enums\FinanceCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** PRD §4.7 "Alokasi Persentase Otomatis" — see FinanceAllocationService. */
class FinanceAllocationConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'label',
        'percentage',
        'kategori',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'percentage' => 'decimal:2',
            'kategori' => FinanceCategory::class,
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
