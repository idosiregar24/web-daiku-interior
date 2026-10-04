<?php

namespace App\Models;

use App\Enums\KpiDirection;
use App\Enums\KpiIndicatorSource;
use App\Enums\KpiPeriodStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * SDM (Sprint 10, §3.3) — an employee's score on one indicator in one
 * month, with the indicator snapshotted in. Locked once its period is
 * CLOSED: the model refuses update/delete then. Written by KpiService.
 */
class KpiScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'kpi_period_id',
        'employee_id',
        'kpi_indicator_id',
        'indicator_name',
        'source',
        'metric_key',
        'target',
        'weight',
        'direction',
        'actual',
        'score',
        'weighted_score',
        'input_by',
    ];

    protected function casts(): array
    {
        return [
            'source' => KpiIndicatorSource::class,
            'direction' => KpiDirection::class,
            'target' => 'decimal:2',
            'weight' => 'decimal:2',
            'actual' => 'decimal:2',
            'score' => 'decimal:2',
            'weighted_score' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (KpiScore $score) {
            if (KpiPeriod::whereKey($score->kpi_period_id)->where('status', KpiPeriodStatus::Closed->value)->exists()) {
                throw new LogicException('Skor KPI periode yang sudah ditutup tidak bisa diubah.');
            }
        };

        static::updating($guard);
        static::deleting($guard);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(KpiPeriod::class, 'kpi_period_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(KpiIndicator::class, 'kpi_indicator_id');
    }

    public function inputter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'input_by');
    }
}
