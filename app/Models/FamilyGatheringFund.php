<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRD §4.7 "Dana Family Gathering" — append-only ledger
 * (database-standards.md §3, no soft delete, no destroy route/policy).
 * INCOME rows come only from PenaltyService's automated job; EXPENSE rows
 * are Finance manually recording "Penggunaan Dana" (PRD §4.7 business
 * rule: "Dana penalti tidak bisa dicairkan tanpa record Penggunaan Dana").
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

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
