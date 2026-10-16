<?php

namespace App\Models;

use App\Enums\WeeklyReportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Sprint 24 D10 — the Pengawas's Friday report for one week of production
 * (`week_start` = that week's Monday). A rejected report is corrected and
 * re-sent on the same row; never deleted.
 */
class WeeklyReport extends Model
{
    protected $fillable = [
        'project_id',
        'supervisor_id',
        'week_start',
        'week_number',
        'notes',
        'links',
        'status',
        'submitted_at',
        'decided_by',
        'decided_at',
        'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'status' => WeeklyReportStatus::class,
            'week_start' => 'date',
            'week_number' => 'integer',
            'links' => 'array',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** The weekly payment the PM approved with this report (if any). */
    public function payment(): HasOne
    {
        return $this->hasOne(SupervisorPayment::class);
    }
}
