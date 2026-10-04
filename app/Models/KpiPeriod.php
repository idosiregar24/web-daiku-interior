<?php

namespace App\Models;

use App\Enums\KpiPeriodStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** SDM (Sprint 10, §3.3) — one month of KPI scoring (`period` = YYYY-MM). */
class KpiPeriod extends Model
{
    use HasFactory;

    protected $fillable = [
        'period',
        'status',
        'closed_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => KpiPeriodStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    public function scores(): HasMany
    {
        return $this->hasMany(KpiScore::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isClosed(): bool
    {
        return $this->status === KpiPeriodStatus::Closed;
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', KpiPeriodStatus::Closed->value);
    }
}
