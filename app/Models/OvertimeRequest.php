<?php

namespace App\Models;

use App\Enums\OvertimeStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** PRD §4.5/§6.6 "Alur Pengajuan Lembur" — see OvertimeService for the sequential PM→Finance approval state machine. */
class OvertimeRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_id',
        'project_id',
        'task_id',
        'hours',
        'rate_per_hour',
        'total_amount',
        'work_date',
        'reason',
        'reject_note',
        'status',
        'pm_approved_by',
        'pm_approved_at',
        'finance_approved_by',
        'finance_approved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OvertimeStatus::class,
            'hours' => 'decimal:2',
            'rate_per_hour' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'work_date' => 'date',
            'pm_approved_at' => 'datetime',
            'finance_approved_at' => 'datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function pmApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pm_approved_by');
    }

    public function financeApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finance_approved_by');
    }

    public function scopeForStaff(Builder $query, ?int $staffId): Builder
    {
        return $query->when($staffId, fn (Builder $q) => $q->where('staff_id', $staffId));
    }

    public function scopeByStatus(Builder $query, ?string $status): Builder
    {
        return $query->when($status, fn (Builder $q) => $q->where('status', $status));
    }
}
