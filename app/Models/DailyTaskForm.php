<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRD §4.5 "Daily Form Wajib" — one submission per task per day (DB-level
 * unique(task_id, work_date), not just app validation). Append-only in
 * practice: no update/destroy route exists, matching how PenaltyService
 * (§6.5) treats "sudah diisi hari ini" as immutable fact once true.
 */
class DailyTaskForm extends Model
{
    use HasFactory;

    // Migration has neither created_at nor updated_at — only
    // `submitted_at` (see the daily_task_forms migration), so both
    // Eloquent timestamp columns are off, not just UPDATED_AT.
    public $timestamps = false;

    protected $fillable = [
        'task_id',
        'staff_id',
        'work_date',
        'status_update',
        'kendala',
        'notes',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'status_update' => TaskStatus::class,
            'work_date' => 'date',
            'submitted_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function scopeForDate(Builder $query, ?string $date): Builder
    {
        // whereDate(), not where() — `work_date` is cast to 'date' and
        // stores with a midnight time component, so a bare string
        // comparison never matches (see DailyTaskFormService::store()'s
        // comment for the full explanation).
        return $query->when($date, fn (Builder $q) => $q->whereDate('work_date', $date));
    }
}
