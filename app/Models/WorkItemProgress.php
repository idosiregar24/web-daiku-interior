<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Sprint 29 Sub 09 (C1) — the % a pengecekan added to one RAB item (File B
 * "CEK n"). Append-only: a correction is a new negative row with a note;
 * Σ per item stays 0–100 (WorkItemService::recordProgress()).
 */
class WorkItemProgress extends Model
{
    protected $table = 'work_item_progress';

    protected $fillable = [
        'project_id',
        'project_work_item_id',
        'weekly_report_id',
        'checked_on',
        'percent',
        'note',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'checked_on' => 'date',
            'percent' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Progres item bersifat append-only dan tidak bisa diubah.'));
        static::deleting(fn () => throw new LogicException('Progres item bersifat append-only dan tidak bisa dihapus.'));
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(ProjectWorkItem::class, 'project_work_item_id');
    }

    public function weeklyReport(): BelongsTo
    {
        return $this->belongsTo(WeeklyReport::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
