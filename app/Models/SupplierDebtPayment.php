<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One installment against a SupplierDebt (PRD §4.7 "riwayat pembayaran").
 * Append-only — the table has no `updated_at`. Each row has a matching
 * HUTANG_IDEAL FinanceTransaction (reference_id = supplier_debt_id).
 */
class SupplierDebtPayment extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'supplier_debt_id',
        'amount',
        'paid_date',
        'bank_account_id',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_date' => 'date:Y-m-d',
        ];
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(SupplierDebt::class, 'supplier_debt_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
