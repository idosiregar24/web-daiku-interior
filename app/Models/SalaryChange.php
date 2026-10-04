<?php

namespace App\Models;

use App\Enums\SalaryChangeStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * SDM (Sprint 10, decision #3) — a base-salary change HR requests and the
 * CEO decides. Written only by SalaryChangeService; approval updates
 * `employees.base_salary` in the same transaction. Never deleted, and a
 * decided row can't change again.
 */
class SalaryChange extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'old_salary',
        'new_salary',
        'effective_date',
        'reason',
        'status',
        'reject_note',
        'performance_review_id',
        'requested_by',
        'decided_by',
        'decided_at',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SalaryChangeStatus::class,
            'old_salary' => 'decimal:2',
            'new_salary' => 'decimal:2',
            'effective_date' => 'date:Y-m-d',
            'decided_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (SalaryChange $change) {
            // getOriginal() returns the cast value — the enum case.
            if ($change->getOriginal('status') === SalaryChangeStatus::Pending) {
                return;
            }

            // The only change a decided row accepts: stamping `applied_at`
            // once on an APPROVED row whose effective date arrived after the
            // approval (SalaryChangeService::applyDue()).
            $dirty = array_keys(array_diff_key($change->getDirty(), ['updated_at' => true]));
            $appliesOnce = $change->getOriginal('status') === SalaryChangeStatus::Approved
                && $change->getOriginal('applied_at') === null
                && $dirty === ['applied_at'];

            if (! $appliesOnce) {
                throw new LogicException('Pengajuan perubahan gaji yang sudah diputuskan tidak bisa diubah.');
            }
        });
        static::deleting(fn () => throw new LogicException('Pengajuan perubahan gaji tidak bisa dihapus.'));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function performanceReview(): BelongsTo
    {
        return $this->belongsTo(PerformanceReview::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', SalaryChangeStatus::Pending->value);
    }
}
