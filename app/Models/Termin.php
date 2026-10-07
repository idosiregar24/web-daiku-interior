<?php

namespace App\Models;

use App\Enums\PaymentTermTrigger;
use App\Enums\TerminStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** PRD §4.4/§4.7/§6.4 "Termin Schedule" — jadwal Sabtu, lihat TerminService. */
class Termin extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'milestone_id',
        'payment_term_id',
        'quotation_id',
        'trigger',
        'milestone_name',
        'termin_number',
        'percentage',
        'amount',
        'dp_amount',
        'pelunasan',
        'scheduled_date',
        'status',
        'bank_account_id',
        'invoice_url',
        'invoice_id',
        'invoice_reminded_at',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TerminStatus::class,
            'trigger' => PaymentTermTrigger::class,
            'percentage' => 'decimal:2',
            'invoice_reminded_at' => 'datetime',
            'amount' => 'decimal:2',
            'dp_amount' => 'decimal:2',
            'pelunasan' => 'decimal:2',
            // DB-generated (amount - dp_amount - pelunasan) — never written by the app.
            'sisa_piutang' => 'decimal:2',
            'scheduled_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    /** Sprint 12 #29 — the RAB this termin bills: the RAB Fix, or an addendum (invoice type TAMBAHAN). */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /** Sprint 12 #12 — the row of the RAB's payment scheme this termin came from ("DP", "Pelunasan"…). */
    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(QuotationPaymentTerm::class, 'payment_term_id');
    }

    /** Sprint 12 #20 — the invoice Marketing issued for this termin. */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /** Derived display state "Dibayar Sebagian" — not a DB status (see TerminService::recordPayment()). */
    public function isPartiallyPaid(): bool
    {
        return ((float) $this->dp_amount + (float) $this->pelunasan) > 0 && (float) $this->sisa_piutang > 0;
    }

    public function scopeByStatus(Builder $query, ?string $status): Builder
    {
        return $query->when($status, fn (Builder $q) => $q->where('status', $status));
    }
}
