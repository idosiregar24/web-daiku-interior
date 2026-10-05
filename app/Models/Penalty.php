<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * PRD §6.5 — created only by PenaltyService::runDailyCheck() (via
 * DailyPenaltyJob); never edited or deleted by hand. The one later write
 * is PenaltyCollectionService marking it paid (Sprint 9 decision #10:
 * the tukang pays manually, wages are not deducted) — `is_deducted` then
 * means "lunas", with `collected_at`/`collected_by`/
 * `finance_transaction_id` recording the payment. "Penalty – View"
 * (PRD §7.1) is read-only, Field Staff scoped to their own (`R*`) — see
 * PenaltyController::index().
 */
class Penalty extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    public const STATUS_UNPAID = 'BELUM_DIBAYAR';

    public const STATUS_PAID = 'LUNAS';

    protected $fillable = [
        'staff_id',
        'type',
        'reference_id',
        'amount',
        'date_occurred',
        'is_deducted',
        'collected_at',
        'collected_by',
        'finance_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'date_occurred' => 'date',
            'is_deducted' => 'boolean',
            'collected_at' => 'datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    /** The PEMASUKAN PENALTY_COLLECT transaction that paid it (null while unpaid). */
    public function financeTransaction(): BelongsTo
    {
        return $this->belongsTo(FinanceTransaction::class);
    }

    /** Its INCOME row in the Dana Family Gathering ledger. */
    public function fundEntry(): HasOne
    {
        return $this->hasOne(FamilyGatheringFund::class, 'source_penalty_id')->where('type', 'INCOME');
    }

    public function scopeForStaff(Builder $query, ?int $staffId): Builder
    {
        return $query->when($staffId, fn (Builder $q) => $q->where('staff_id', $staffId));
    }

    /** Penalties that occurred in the calendar month of `$month` (Sprint 13 H10 — "Penalti bulan ini"). */
    public function scopeInMonth(Builder $query, Carbon $month): Builder
    {
        return $query
            ->whereDate('date_occurred', '>=', $month->copy()->startOfMonth()->toDateString())
            ->whereDate('date_occurred', '<=', $month->copy()->endOfMonth()->toDateString());
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->where('is_deducted', false);
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('is_deducted', true);
    }

    /** `BELUM_DIBAYAR` / `LUNAS` (the Penalty page filter); anything else leaves the query unfiltered. */
    public function scopeByPaymentStatus(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            self::STATUS_UNPAID => $query->unpaid(),
            self::STATUS_PAID => $query->paid(),
            default => $query,
        };
    }
}
