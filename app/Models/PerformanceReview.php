<?php

namespace App\Models;

use App\Enums\ReviewGrade;
use App\Enums\ReviewRecommendation;
use App\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * SDM (Sprint 10, §3.4) — a semester performance review. State machine
 * and the "locked from APPROVED on" rule live in PerformanceReviewService;
 * the model only refuses deletion.
 */
class PerformanceReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'year',
        'semester',
        'kpi_average',
        'kpi_months',
        'discipline_summary',
        'discipline_score',
        'qualitative',
        'qualitative_score',
        'weights',
        'final_score',
        'grade',
        'recommendation',
        'notes',
        'status',
        'return_note',
        'reviewer_id',
        'submitted_at',
        'approved_by',
        'approved_at',
        'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReviewStatus::class,
            'grade' => ReviewGrade::class,
            'recommendation' => ReviewRecommendation::class,
            'year' => 'integer',
            'semester' => 'integer',
            'kpi_average' => 'decimal:2',
            'kpi_months' => 'integer',
            'discipline_summary' => 'array',
            'discipline_score' => 'decimal:2',
            'qualitative' => 'array',
            'qualitative_score' => 'decimal:2',
            'weights' => 'array',
            'final_score' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Evaluasi kinerja tidak bisa dihapus.'));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** APPROVED or ACKNOWLEDGED — what the employee may see (decision #6). */
    public function scopeFinal(Builder $query): Builder
    {
        return $query->whereIn('status', [ReviewStatus::Approved->value, ReviewStatus::Acknowledged->value]);
    }

    /** "Semester 1 2026" */
    public function periodLabel(): string
    {
        return "Semester {$this->semester} {$this->year}";
    }
}
