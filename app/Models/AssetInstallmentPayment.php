<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One installment paid on an Asset (PRD §4.7 "Aset & Cicilan"), with its
 * cash-out FinanceTransaction (PENGELUARAN / ANGSURAN). Written only by
 * AssetInstallmentService::recordPayment().
 *
 * Append-only finance ledger (PRD §9.4): no `updated_at`, no route to
 * change it, and the model refuses update/delete like AuditLog does —
 * a wrong payment is corrected by a new record, never by rewriting one.
 */
class AssetInstallmentPayment extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'asset_id',
        'amount',
        'paid_at',
        'bank_account_id',
        'finance_transaction_id',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'date:Y-m-d',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Pembayaran cicilan aset bersifat append-only dan tidak bisa diubah.'));
        static::deleting(fn () => throw new LogicException('Pembayaran cicilan aset bersifat append-only dan tidak bisa dihapus.'));
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function financeTransaction(): BelongsTo
    {
        return $this->belongsTo(FinanceTransaction::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
