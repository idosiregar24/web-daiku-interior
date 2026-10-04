<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PRD §4.7 "Hutang Supplier" — see SupplierDebtService for the cash-flow
 * rule. `remaining` is a stored generated column (total − paid), never
 * written by the app. Append-only (PRD §9.4): no SoftDeletes, no edit.
 */
class SupplierDebt extends Model
{
    use HasFactory;

    /** Derived display statuses — keys already mapped in StatusChip. */
    public const STATUS_LUNAS = 'LUNAS';

    public const STATUS_JATUH_TEMPO = 'JATUH_TEMPO';

    public const STATUS_BERJALAN = 'BERJALAN';

    public const STATUSES = [self::STATUS_LUNAS, self::STATUS_JATUH_TEMPO, self::STATUS_BERJALAN];

    protected $fillable = [
        'vendor_id',
        'total_amount',
        'paid_amount',
        'project_id',
        'description',
        'due_date',
        'created_by',
    ];

    protected $appends = ['status'];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'remaining' => 'decimal:2',
            'due_date' => 'date:Y-m-d',
        ];
    }

    /** Master Vendor (Sprint 11 Sub 2) — replaced the free-text `supplier_name`. */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierDebtPayment::class);
    }

    /** LUNAS (remaining ≤ 0) / JATUH_TEMPO (overdue) / BERJALAN. */
    protected function status(): Attribute
    {
        return Attribute::get(function (): string {
            $remaining = (float) ($this->remaining ?? ((float) $this->total_amount - (float) $this->paid_amount));

            if ($remaining <= 0) {
                return self::STATUS_LUNAS;
            }

            if ($this->due_date && $this->due_date->lt(today())) {
                return self::STATUS_JATUH_TEMPO;
            }

            return self::STATUS_BERJALAN;
        });
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('remaining', '>', 0);
    }

    public function scopeSettled(Builder $query): Builder
    {
        return $query->where('remaining', '<=', 0);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->outstanding()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', today()->toDateString());
    }

    /** Outstanding and not (yet) past due — no due date counts as not overdue. */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->outstanding()->where(fn (Builder $q) => $q
            ->whereNull('due_date')
            ->orWhereDate('due_date', '>=', today()->toDateString()));
    }

    /** Filter by derived display status (LUNAS / JATUH_TEMPO / BERJALAN); unknown values are ignored. */
    public function scopeByDisplayStatus(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            self::STATUS_LUNAS => $query->settled(),
            self::STATUS_JATUH_TEMPO => $query->overdue(),
            self::STATUS_BERJALAN => $query->running(),
            default => $query,
        };
    }

    public function scopeSearchSupplier(Builder $query, ?string $search): Builder
    {
        return $query->when($search, fn (Builder $q) => $q->whereHas(
            'vendor',
            fn (Builder $vendor) => $vendor->where('name', 'like', '%'.$search.'%'),
        ));
    }
}
