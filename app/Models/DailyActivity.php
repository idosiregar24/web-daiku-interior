<?php

namespace App\Models;

use App\Support\DailyFormSchedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 29 Sub 13 (I1) — one entry of an employee's "Aktivitas Harian".
 * Written only through DailyActivityService, for today only, and editable
 * only on its own day (DailyActivityPolicy). Field staff never write here —
 * their day is the daily target (Sprint 24).
 */
class DailyActivity extends Model
{
    protected $fillable = [
        'user_id',
        'date',
        'description',
        'project_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scopeOnDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('date', $date);
    }

    /** Still today (WIB) — the only day an entry can be changed or removed. */
    public function isEditableToday(): bool
    {
        return $this->date?->toDateString() === DailyFormSchedule::now()->toDateString();
    }
}
