<?php

namespace App\Models;

use App\Enums\WeeklyReportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Sprint 24 D10 — the Pengawas's Friday report for one week of production
 * (`week_start` = that week's Monday). A rejected report is corrected and
 * re-sent on the same row; never deleted.
 *
 * Sprint 31 — with wages per RAB item a week may hold several pengecekan,
 * numbered by `sequence`; each is its own row (WeeklyReportService).
 */
class WeeklyReport extends Model
{
    protected $fillable = [
        'project_id',
        'supervisor_id',
        'week_start',
        'week_number',
        'sequence',
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
            'sequence' => 'integer',
            'links' => 'array',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /** "Minggu ke-3", or "Minggu ke-3 · Pengecekan ke-2" for a later one of the same week — every place that names a report. */
    public function label(): string
    {
        return "Minggu ke-{$this->week_number}".((int) $this->sequence > 1 ? " · Pengecekan ke-{$this->sequence}" : '');
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

    /**
     * The weekly payment of this report (if any) — the latest one: a report
     * rejected and re-sent asks again (Sprint 29 Sub 09).
     */
    public function payment(): HasOne
    {
        return $this->hasOne(SupervisorPayment::class)->latestOfMany();
    }

    /** Sprint 29 Sub 09 — the % per RAB item sent with this report. */
    public function progressRows(): HasMany
    {
        return $this->hasMany(WorkItemProgress::class);
    }
}
