<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 12 decisions #20–#21 — issued by Marketing, verified by Finance.
 * Every status change goes through App\Services\InvoiceService; finance
 * data, so never deleted.
 */
class Invoice extends Model
{
    protected $fillable = [
        'number',
        'lead_id',
        'project_id',
        'quotation_id',
        'termin_id',
        'type',
        'amount',
        'due_date',
        'status',
        'issued_by',
        'issued_at',
        'payment_proof_url',
        'proof_submitted_by',
        'proof_submitted_at',
        'bank_account_id',
        'paid_date',
        'verified_by',
        'verified_at',
        'finance_transaction_id',
        'reject_reason',
        'rejected_by',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class,
            'status' => InvoiceStatus::class,
            'amount' => 'decimal:2',
            'due_date' => 'date:Y-m-d',
            'paid_date' => 'date:Y-m-d',
            'issued_at' => 'datetime',
            'proof_submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function termin(): BelongsTo
    {
        return $this->belongsTo(Termin::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function financeTransaction(): BelongsTo
    {
        return $this->belongsTo(FinanceTransaction::class);
    }

    public function scopeByStatus(Builder $query, ?string $status): Builder
    {
        return $query->when($status, fn (Builder $q) => $q->where('status', $status));
    }

    public function scopeByType(Builder $query, ?string $type): Builder
    {
        return $query->when($type, fn (Builder $q) => $q->where('type', $type));
    }
}
