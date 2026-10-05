<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * Sprint 12 decision #27 — the real cost of an allocated item (qty riil ×
 * harga modal). Append-only: a correction is a new row with negative
 * amounts pointing at the row it cancels (`reverses_id`). Written only by
 * BudgetRealizationService.
 */
class BudgetRealization extends Model
{
    protected $fillable = [
        'budget_line_id',
        'qty_actual',
        'unit_cost',
        'total_cost',
        'vendor_id',
        'note',
        'reverses_id',
        'overrun_request_id',
        'recorded_by',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'qty_actual' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'recorded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Realisasi bersifat append-only — koreksi dengan baris pembatalan.'));
        static::deleting(fn () => throw new LogicException('Realisasi bersifat append-only dan tidak bisa dihapus.'));
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'budget_line_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** The row this one cancels (a correction). */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    /** The correction that cancelled this row, if any. */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }

    public function overrunRequest(): BelongsTo
    {
        return $this->belongsTo(BudgetOverrunRequest::class);
    }
}
