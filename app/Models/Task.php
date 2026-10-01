<?php

namespace App\Models;

use App\Enums\FinanceCategory;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Task extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'milestone_id',
        'title',
        'description',
        'assignee_id',
        'created_by',
        'due_date',
        'status',
        'pre_overdue_status',
        'priority',
        'is_locked',
        'kendala',
        'note',
        'rate_per_task',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'pre_overdue_status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_date' => 'date',
            'is_locked' => 'boolean',
            'rate_per_task' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function dailyTaskForms(): HasMany
    {
        return $this->hasMany(DailyTaskForm::class);
    }

    public function overtimeRequests(): HasMany
    {
        return $this->hasMany(OvertimeRequest::class);
    }

    /**
     * The wage payment for this task, if Finance recorded one — same
     * marker as StaffPaymentService::isTaskPaid() (GAJI_KARYAWAN with
     * reference_id = task id, Sprint 9 decision #7). Read-side only
     * (`withExists('wagePayment as is_wage_paid')` for the task lists);
     * write paths ask StaffPaymentService.
     */
    public function wagePayment(): HasOne
    {
        return $this->hasOne(FinanceTransaction::class, 'reference_id')
            ->where('kategori', FinanceCategory::GajiKaryawan->value);
    }

    public function scopeByStatus(Builder $query, ?string $status): Builder
    {
        return $query->when($status, fn (Builder $q) => $q->where('status', $status));
    }

    public function scopeByAssignee(Builder $query, ?int $assigneeId): Builder
    {
        return $query->when($assigneeId, fn (Builder $q) => $q->where('assignee_id', $assigneeId));
    }

    /** Past due_date and not yet DONE — PRD §4.5 OVER status. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('due_date', '<', now()->toDateString())
            ->where('status', '!=', TaskStatus::Done->value);
    }

    /**
     * PRD §4.5 "Task List … (hari ini & minggu ini)" — `today`, `week`
     * (Monday–Sunday of the current week) or `overdue`, all in WIB.
     * whereDate() rather than plain comparisons: the `date` cast stores
     * a midnight time component on SQLite, which would drop the last day
     * of a string range.
     */
    public function scopeByDue(Builder $query, ?string $due): Builder
    {
        $today = now('Asia/Jakarta');

        return match ($due) {
            'today' => $query->whereDate('due_date', $today->toDateString()),
            'week' => $query
                ->whereDate('due_date', '>=', $today->copy()->startOfWeek(Carbon::MONDAY)->toDateString())
                ->whereDate('due_date', '<=', $today->copy()->endOfWeek(Carbon::SUNDAY)->toDateString()),
            'overdue' => $query->overdue(),
            default => $query,
        };
    }
}
