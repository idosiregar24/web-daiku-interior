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
}
