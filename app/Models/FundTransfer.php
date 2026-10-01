<?php

namespace App\Models;

use App\Enums\FinanceCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PRD §4.7 "Pindah Dana" — one move of money between two of the
 * company's own accounts. Its cash effect lives in two FinanceTransaction
 * legs (PENGELUARAN on the source, PEMASUKAN on the destination), both
 * `kategori = PINDAH_DANA` and `reference_id = this id`; written only by
 * FundTransferService. Append-only — the table has `created_at` only.
 */
class FundTransfer extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'from_bank_account_id',
        'to_bank_account_id',
        'amount',
        'date',
        'description',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'date' => 'date:Y-m-d',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Pindah dana bersifat append-only dan tidak bisa diubah.'));
        static::deleting(fn () => throw new \LogicException('Pindah dana bersifat append-only dan tidak bisa dihapus.'));
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'from_bank_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'to_bank_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The two legs. */
    public function transactions(): HasMany
    {
        return $this->hasMany(FinanceTransaction::class, 'reference_id')
            ->where('kategori', FinanceCategory::PindahDana->value);
    }
}
