<?php

namespace App\Models;

use App\Enums\AssetCondition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PRD §4.8 "Aset Inventaris" — alat, mesin, kendaraan, dengan kondisi dan
 * lokasi — plus PRD §4.7 "Aset & Cicilan": an optional installment plan
 * (`has_installment`, `total_install`, `installment_amount`,
 * `installment_due_day`) that Logistics sets on the asset form, paid off
 * by Finance through AssetInstallmentService.
 *
 * `paid_install` is not fillable: it mirrors the append-only
 * `asset_installment_payments` ledger and only AssetInstallmentService
 * changes it, under a row lock.
 */
class Asset extends Model
{
    use HasFactory;

    /** Derived installment statuses — keys already mapped in StatusChip. */
    public const INSTALLMENT_PAID_OFF = 'LUNAS';

    public const INSTALLMENT_OVERDUE = 'JATUH_TEMPO';

    public const INSTALLMENT_ONGOING = 'BERJALAN';

    public const INSTALLMENT_STATUSES = [self::INSTALLMENT_ONGOING, self::INSTALLMENT_OVERDUE, self::INSTALLMENT_PAID_OFF];

    protected $fillable = [
        'name',
        'category',
        'purchase_date',
        'value',
        'condition',
        'location',
        'notes',
        'has_installment',
        'total_install',
        'installment_amount',
        'installment_due_day',
    ];

    protected $appends = ['remaining_install'];

    protected function casts(): array
    {
        return [
            'condition' => AssetCondition::class,
            'purchase_date' => 'date',
            'value' => 'decimal:2',
            'has_installment' => 'boolean',
            'total_install' => 'decimal:2',
            'paid_install' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'installment_due_day' => 'integer',
        ];
    }

    public function installmentPayments(): HasMany
    {
        return $this->hasMany(AssetInstallmentPayment::class);
    }

    /** Still to pay on the plan ("Sisa Cicilan"), never negative; null without a plan. */
    protected function remainingInstall(): Attribute
    {
        return Attribute::get(function (): ?string {
            if (! $this->has_installment || $this->total_install === null) {
                return null;
            }

            $remainingCents = (int) round((float) $this->total_install * 100) - (int) round((float) $this->paid_install * 100);

            return number_format(max(0, $remainingCents) / 100, 2, '.', '');
        });
    }

    /**
     * LUNAS (nothing left) / JATUH_TEMPO (the due day has passed this month
     * and nothing was paid this month) / BERJALAN; null without a plan.
     * Not appended by default — it may need a query; lists load
     * `paid_this_month` with withPaidThisMonth() instead.
     */
    protected function installmentStatus(): Attribute
    {
        return Attribute::get(function (): ?string {
            if (! $this->has_installment || $this->total_install === null) {
                return null;
            }

            if ((float) $this->remaining_install <= 0) {
                return self::INSTALLMENT_PAID_OFF;
            }

            $dueDayPassed = $this->installment_due_day !== null && today()->day > $this->installment_due_day;

            return $dueDayPassed && ! $this->paidThisMonth() ? self::INSTALLMENT_OVERDUE : self::INSTALLMENT_ONGOING;
        });
    }

    private function paidThisMonth(): bool
    {
        if (array_key_exists('paid_this_month', $this->attributes)) {
            return (bool) $this->attributes['paid_this_month'];
        }

        return $this->installmentPayments()->whereBetween('paid_at', self::currentMonthRange())->exists();
    }

    /** @return array{0: string, 1: string} */
    private static function currentMonthRange(): array
    {
        return [today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString()];
    }

    public function scopeByCondition(Builder $query, ?string $condition): Builder
    {
        return $query->when($condition, fn (Builder $q) => $q->where('condition', $condition));
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
            ->where('name', 'like', '%'.$term.'%')
            ->orWhere('location', 'like', '%'.$term.'%')));
    }

    public function scopeWithInstallmentPlan(Builder $query): Builder
    {
        return $query->where('has_installment', true)->whereNotNull('total_install');
    }

    /** Adds a `paid_this_month` flag, so installment_status needs no query per row. */
    public function scopeWithPaidThisMonth(Builder $query): Builder
    {
        return $query->withExists([
            'installmentPayments as paid_this_month' => fn (Builder $q) => $q->whereBetween('paid_at', self::currentMonthRange()),
        ]);
    }

    public function scopeInstallmentOutstanding(Builder $query): Builder
    {
        return $query->withInstallmentPlan()->whereColumn('paid_install', '<', 'total_install');
    }

    public function scopeInstallmentPaidOff(Builder $query): Builder
    {
        return $query->withInstallmentPlan()->whereColumn('paid_install', '>=', 'total_install');
    }

    /** Same rule as installment_status's JATUH_TEMPO, in SQL. */
    public function scopeInstallmentOverdue(Builder $query): Builder
    {
        return $query->installmentOutstanding()
            ->whereNotNull('installment_due_day')
            ->where('installment_due_day', '<', today()->day)
            ->whereDoesntHave('installmentPayments', fn (Builder $q) => $q->whereBetween('paid_at', self::currentMonthRange()));
    }

    public function scopeInstallmentRunning(Builder $query): Builder
    {
        return $query->installmentOutstanding()->where(fn (Builder $q) => $q
            ->whereNull('installment_due_day')
            ->orWhere('installment_due_day', '>=', today()->day)
            ->orWhereHas('installmentPayments', fn (Builder $payments) => $payments->whereBetween('paid_at', self::currentMonthRange())));
    }

    /** Filter by derived installment status; unknown values are ignored. */
    public function scopeByInstallmentStatus(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            self::INSTALLMENT_PAID_OFF => $query->installmentPaidOff(),
            self::INSTALLMENT_OVERDUE => $query->installmentOverdue(),
            self::INSTALLMENT_ONGOING => $query->installmentRunning(),
            default => $query,
        };
    }
}
