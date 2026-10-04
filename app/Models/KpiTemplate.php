<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** SDM (Sprint 10, §3.3) — the KPI indicators of one position (jabatan). */
class KpiTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'position_id',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function indicators(): HasMany
    {
        return $this->hasMany(KpiIndicator::class)->orderBy('sort_order')->orderBy('id');
    }
}
