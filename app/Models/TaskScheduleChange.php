<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 24 D8/D9 — append-only history of a daily target's date: rolled
 * over by the system, moved (GESER) or swapped (TUKAR) by the Pengawas/PM,
 * changed in the full edit (EDIT).
 */
class TaskScheduleChange extends Model
{
    public const ROLLOVER = 'ROLLOVER';

    public const GESER = 'GESER';

    public const TUKAR = 'TUKAR';

    public const EDIT = 'EDIT';

    public const UPDATED_AT = null;

    protected $fillable = [
        'task_id',
        'from_date',
        'to_date',
        'kind',
        'changed_by',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
