<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PRD §4.7 "Pinjaman Tukang". `remaining` is a DB stored generated column
 * (amount - paid_amount) — never written from PHP, hence not fillable.
 * Append-only (PRD §9.4): only `paid_amount` ever changes, and only via
 * StaffLoanService::recordPayment().
 */
class StaffLoan extends Model
{
    use HasFactory;

    public const STATUS_ONGOING = 'BERJALAN';

    public const STATUS_PAID_OFF = 'LUNAS';

    protected $fillable = [
        'staff_id',
        'amount',
        'paid_amount',
        'installment_amount',
        'description',
        'bank_account_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'remaining' => 'decimal:2',
            'installment_amount' => 'decimal:2',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(StaffLoanPayment::class);
    }

    /** Display status — `LUNAS` once nothing remains, else `BERJALAN` (StatusChip keys). */
    public function getStatusAttribute(): string
    {
        return (float) $this->remaining <= 0 ? self::STATUS_PAID_OFF : self::STATUS_ONGOING;
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('remaining', '>', 0);
    }

    public function scopeByStatus(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            self::STATUS_PAID_OFF => $query->where('remaining', '<=', 0),
            self::STATUS_ONGOING => $query->where('remaining', '>', 0),
            default => $query,
        };
    }

    public function scopeByStaff(Builder $query, ?int $staffId): Builder
    {
        return $query->when($staffId, fn (Builder $q) => $q->where('staff_id', $staffId));
    }
}
