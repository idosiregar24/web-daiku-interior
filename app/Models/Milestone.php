<?php

namespace App\Models;

use App\Enums\MilestoneStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Shown to users as a **Timeline** (Sprint 23 K16 — display name only; code,
 * tables and routes stay `milestone`). Runs from `start_date` to
 * `target_date` ("Tanggal Selesai", K17); overdue reads `target_date` only.
 * `start_date` is required for new timelines and backfilled for older ones.
 */
class Milestone extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'name',
        'start_date',
        'target_date',
        'status',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'status' => MilestoneStatus::class,
            // Sprint 24 — serialised as "2026-10-15" (see Task::casts()).
            'start_date' => 'date:Y-m-d',
            'target_date' => 'date:Y-m-d',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function qaForm(): HasOne
    {
        return $this->hasOne(QaForm::class);
    }

    public function scopeByStatus(Builder $query, ?string $status): Builder
    {
        return $query->when($status, fn (Builder $q) => $q->where('status', $status));
    }
}
