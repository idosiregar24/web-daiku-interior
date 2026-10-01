<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One monthly salary paid to an Employee (PRD §4.7 "Gaji Karyawan Tetap").
 * Written only by PayrollService::pay(); `amount` = base_salary (snapshot)
 * + allowance − deduction. Linked to its GAJI_KARYAWAN FinanceTransaction
 * through `finance_transaction_id` — that transaction's `reference_id`
 * stays NULL (see PayrollService).
 *
 * Append-only (PRD §9.4): no `updated_at`, and the model refuses
 * update/delete like AuditLog does.
 */
class SalaryPayment extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'employee_id',
        'period',
        'base_salary',
        'allowance',
        'deduction',
        'amount',
        'bank_account_id',
        'paid_at',
        'note',
        'finance_transaction_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'base_salary' => 'decimal:2',
            'allowance' => 'decimal:2',
            'deduction' => 'decimal:2',
            'amount' => 'decimal:2',
            'paid_at' => 'date:Y-m-d',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Pembayaran gaji bersifat append-only dan tidak bisa diubah.'));
        static::deleting(fn () => throw new LogicException('Pembayaran gaji bersifat append-only dan tidak bisa dihapus.'));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
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

    /** `YYYY-MM`. */
    public function scopeForPeriod(Builder $query, string $period): Builder
    {
        return $query->where('period', $period);
    }
}
