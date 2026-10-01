<?php

namespace App\Models;

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRD §4.7 (Modul Finance). `type`/`kategori` split reconciled with
 * daiku_schema.sql in Sprint 4 (see the
 * add_kategori_bank_account_to_finance_transactions_table migration) —
 * writers predating that migration (OvertimeService) updated to match.
 * `reference_id` is read together with `kategori` (termin, loan, debt,
 * task, overtime, fund transfer — see each writer).
 */
class FinanceTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'bank_account_id',
        'type',
        'kategori',
        'amount',
        'description',
        'reference_id',
        'date',
        'created_by',
        'attachments',
    ];

    protected function casts(): array
    {
        return [
            'type' => FinanceTransactionType::class,
            'kategori' => FinanceCategory::class,
            'amount' => 'decimal:2',
            'date' => 'date',
            'attachments' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeByType(Builder $query, ?string $type): Builder
    {
        return $query->when($type, fn (Builder $q) => $q->where('type', $type));
    }

    public function scopeByProject(Builder $query, ?int $projectId): Builder
    {
        return $query->when($projectId, fn (Builder $q) => $q->where('project_id', $projectId));
    }

    public function scopeByBankAccount(Builder $query, ?int $bankAccountId): Builder
    {
        return $query->when($bankAccountId, fn (Builder $q) => $q->where('bank_account_id', $bankAccountId));
    }

    public function scopeByKategori(Builder $query, ?string $kategori): Builder
    {
        return $query->when($kategori, fn (Builder $q) => $q->where('kategori', $kategori));
    }

    /**
     * The Transactions page / export filter set (see
     * FinanceTransactionFilterRequest): type, project_id, bank_account_id,
     * kategori, from, to — each optional. whereDate() for the range: the
     * SQLite test connection stores `date` with a time part.
     *
     * @param  array{type?: ?string, project_id?: int|string|null, bank_account_id?: int|string|null, kategori?: ?string, from?: ?string, to?: ?string}  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->byType($filters['type'] ?? null)
            ->byProject(isset($filters['project_id']) ? (int) $filters['project_id'] : null)
            ->byBankAccount(isset($filters['bank_account_id']) ? (int) $filters['bank_account_id'] : null)
            ->byKategori($filters['kategori'] ?? null)
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->whereDate('date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->whereDate('date', '<=', $to))
            // Set server-side only (never from the query string): salaries
            // are CEO/FINANCE-only (sprint-09 decision #7), so payroll legs
            // are dropped from lists, summaries and exports for other readers.
            ->when($filters['hide_salary_payments'] ?? false, fn (Builder $q) => $q
                ->whereNotIn('id', SalaryPayment::query()->select('finance_transaction_id')));
    }

    /** Whether `$user` may read individual salary transactions (decision #7). */
    public static function canSeeSalaries(?User $user): bool
    {
        return (bool) $user?->hasAnyRole(['CEO', 'FINANCE', 'SUPERADMIN']);
    }

    /**
     * Drops Pindah Dana legs — money moving between the company's own
     * accounts is neither income nor expense for the company as a whole,
     * and its two legs would otherwise count the same money twice
     * (Sprint 9 decision #5). Company-level totals use this; per-account
     * balances must not. Null-safe: legacy rows without a kategori stay.
     */
    public function scopeExcludingInternalTransfers(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('kategori')
            ->orWhere('kategori', '!=', FinanceCategory::PindahDana->value));
    }
}
