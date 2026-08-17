<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRD §6.5 — append-only, written only by PenaltyService::runDailyCheck()
 * (via DailyPenaltyJob). No controller writes to this table; "Penalty –
 * View" (PRD §7.1) is read-only for everyone, Field Staff scoped to their
 * own (`R*`) — see PenaltyController::index().
 */
class Penalty extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'staff_id',
        'type',
        'reference_id',
        'amount',
        'date_occurred',
        'is_deducted',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'date_occurred' => 'date',
            'is_deducted' => 'boolean',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function scopeForStaff(Builder $query, ?int $staffId): Builder
    {
        return $query->when($staffId, fn (Builder $q) => $q->where('staff_id', $staffId));
    }
}
