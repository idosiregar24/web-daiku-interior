<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 12 decision #2 — one numbered follow-up (FU-1, FU-2, …) of a
 * lead. `sequence` and `done_at` only change through LeadService.
 */
class LeadFollowUp extends Model
{
    use HasFactory;

    /** From this follow-up on, the UI suggests marking the lead Lost (not blocking). */
    public const SUGGEST_LOST_FROM = 5;

    protected $fillable = ['lead_id', 'sequence', 'scheduled_date', 'result_note', 'created_by'];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date:Y-m-d',
            'done_at' => 'datetime',
            'sequence' => 'integer',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('done_at');
    }
}
