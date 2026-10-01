<?php

namespace App\Models;

use App\Enums\FinanceTransactionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PRD §4.7 "Multi-Rekening". The balance is derived, never stored
 * (Sprint 9 decision #5): `opening_balance` is the saldo awal before the
 * first transaction recorded in the system, and the running balance is
 * opening_balance + Σ PEMASUKAN − Σ PENGELUARAN of this account's
 * transactions — Pindah Dana legs included, since for a single account
 * a transfer really is money in or out.
 */
class BankAccount extends Model
{
    use HasFactory;

    protected $fillable = ['bank_name', 'account_no', 'label', 'opening_balance', 'is_active'];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'is_active' => 'boolean',
            // Only present when loaded through scopeWithBalance().
            'total_income' => 'decimal:2',
            'total_expense' => 'decimal:2',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinanceTransaction::class);
    }

    /**
     * Adds `total_income`/`total_expense` (all-time sums, one subquery
     * each) so `current_balance` needs no extra query per row — use it
     * on every list that shows balances.
     */
    public function scopeWithBalance(Builder $query): Builder
    {
        return $query
            ->withSum(['transactions as total_income' => fn (Builder $q) => $q->where('type', FinanceTransactionType::Income->value)], 'amount')
            ->withSum(['transactions as total_expense' => fn (Builder $q) => $q->where('type', FinanceTransactionType::Expense->value)], 'amount');
    }

    /**
     * Saldo saat ini. Not in `$appends` (it would cost two queries per
     * serialized account, dropdowns included) — callers that show it
     * `->append('current_balance')` after `withBalance()`. Falls back to
     * querying the sums when the scope wasn't applied.
     */
    protected function currentBalance(): Attribute
    {
        return Attribute::get(function (): float {
            if (array_key_exists('total_income', $this->attributes) && array_key_exists('total_expense', $this->attributes)) {
                $income = (float) $this->attributes['total_income'];
                $expense = (float) $this->attributes['total_expense'];
            } else {
                $income = (float) $this->transactions()->where('type', FinanceTransactionType::Income->value)->sum('amount');
                $expense = (float) $this->transactions()->where('type', FinanceTransactionType::Expense->value)->sum('amount');
            }

            return round((float) $this->opening_balance + $income - $expense, 2);
        });
    }
}
