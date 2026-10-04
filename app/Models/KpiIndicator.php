<?php

namespace App\Models;

use App\Enums\KpiDirection;
use App\Enums\KpiIndicatorSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SDM (Sprint 10, §3.3) — one indicator of a KPI template. AUTO ones carry
 * a `metric_key` KpiService knows how to compute.
 */
class KpiIndicator extends Model
{
    use HasFactory;

    protected $fillable = [
        'kpi_template_id',
        'name',
        'source',
        'metric_key',
        'target',
        'weight',
        'direction',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'source' => KpiIndicatorSource::class,
            'direction' => KpiDirection::class,
            'target' => 'decimal:2',
            'weight' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(KpiTemplate::class, 'kpi_template_id');
    }
}
