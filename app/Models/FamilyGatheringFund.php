<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRD §4.7 "Dana Family Gathering" — append-only ledger
 * (database-standards.md §3, no soft delete, no destroy route/policy).
 * INCOME rows come from PenaltyService's automated job (PRD §6.5 — one
 * per penalty, written when it's issued); EXPENSE rows are Finance
 * recording "Penggunaan Dana" (PRD §4.7: "Dana penalti tidak bisa
 * dicairkan tanpa record Penggunaan Dana"), each paid out of a bank
 * account through `finance_transaction_id`. Only income from penalties
 * already paid is spendable (Sprint 9 decision #10) — see
 * FamilyGatheringFundService::summary().
 */
class FamilyGatheringFund extends Model
{
    use HasFactory;

    // Table is `family_gathering_fund` (singular — see the Sprint 1
    // migration), not Eloquent's default pluralized guess
    // `family_gathering_funds`.
    protected $table = 'family_gathering_fund';

    const UPDATED_AT = null;

    protected $fillable = [
        'type',
        'amount',
        'description',
        'source_penalty_id',
        'finance_transaction_id',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function sourcePenalty(): BelongsTo
    {
        return $this->belongsTo(Penalty::class, 'source_penalty_id');
    }

    /** The PENGELUARAN transaction an EXPENSE row paid out of (null for INCOME rows and legacy expenses). */
    public function financeTransaction(): BelongsTo
    {
        return $this->belongsTo(FinanceTransaction::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeIncome(Builder $query): Builder
    {
        return $query->where('type', 'INCOME');
    }

    public function scopeExpense(Builder $query): Builder
    {
        return $query->where('type', 'EXPENSE');
    }

    /** `INCOME` / `EXPENSE` (the fund page filter); anything else leaves the query unfiltered. */
    public function scopeByType(Builder $query, ?string $type): Builder
    {
        return $query->when(in_array($type, ['INCOME', 'EXPENSE'], true), fn (Builder $q) => $q->where('type', $type));
    }
}
