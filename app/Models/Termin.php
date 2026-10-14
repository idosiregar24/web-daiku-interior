<?php

namespace App\Models;

use App\Enums\InvoiceType;
use App\Enums\PaymentTermTrigger;
use App\Enums\TerminStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

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

    /**
     * Sprint 23 K12 — adds `rab_position` / `rab_count`: where the termin
     * sits among the termins of its own RAB in the project ("Termin 2 dari
     * 4"). A RAB Tambahan's termins count apart from the RAB Fix's; legacy
     * termins without a RAB count together.
     */
    public function scopeWithRabPosition(Builder $query): Builder
    {
        $siblings = fn (bool $upToThis) => DB::table('termins as rab_sibling')
            ->selectRaw('count(*)')
            ->whereColumn('rab_sibling.project_id', 'termins.project_id')
            ->where(fn (QueryBuilder $same) => $same
                ->whereColumn('rab_sibling.quotation_id', 'termins.quotation_id')
                ->orWhere(fn (QueryBuilder $legacy) => $legacy->whereNull('rab_sibling.quotation_id')->whereNull('termins.quotation_id')))
            ->when($upToThis, fn (QueryBuilder $q) => $q->whereColumn('rab_sibling.termin_number', '<=', 'termins.termin_number'));

        return $query->addSelect(['rab_position' => $siblings(true), 'rab_count' => $siblings(false)]);
    }

    /**
     * [position, count] of this termin within its RAB's termins — from
     * withRabPosition() when loaded, else queried.
     *
     * @return array{0: int, 1: int}
     */
    public function rabPosition(): array
    {
        if (isset($this->attributes['rab_position'], $this->attributes['rab_count'])) {
            return [(int) $this->attributes['rab_position'], (int) $this->attributes['rab_count']];
        }

        $siblings = self::query()->where('project_id', $this->project_id)->where('quotation_id', $this->quotation_id);

        return [(clone $siblings)->where('termin_number', '<=', $this->termin_number)->count(), $siblings->count()];
    }

    /**
     * Sprint 12 #20 — what the invoice of this termin bills: TAMBAHAN for
     * every termin of a RAB Tambahan (#29 / D7), DP for the "di muka" row,
     * PELUNASAN for the completion row or the last termin of its RAB,
     * TERMIN otherwise. Used by InvoiceService::issueForTermin() and for
     * termins not invoiced yet (Sprint 23 K12c).
     */
    public function invoiceType(): InvoiceType
    {
        [$position, $count] = $this->rabPosition();
        $isAddendum = $this->quotation_id !== null && $this->quotation_id !== $this->project?->quotation_id;

        return match (true) {
            $isAddendum => InvoiceType::Tambahan,
            $this->trigger === PaymentTermTrigger::DiMuka => InvoiceType::Dp,
            $this->trigger === PaymentTermTrigger::ProyekSelesai, $position >= $count => InvoiceType::Pelunasan,
            default => InvoiceType::Termin,
        };
    }

    /** `append('payment_label')` — paymentLabel() for the frontend (the invoice's own type once invoiced). */
    public function getPaymentLabelAttribute(): string
    {
        return $this->paymentLabel($this->invoice_id !== null ? $this->invoice?->type : null);
    }

    /**
     * Sprint 23 K12 — "DP", "Termin 2 dari 4 · 30%", "Pelunasan",
     * "Pekerjaan Tambahan" (with "1 dari 2 · 50%" when the RAB Tambahan
     * is billed in several termins). `$type` = the invoice's own type when
     * there is one (it was fixed when issued).
     */
    public function paymentLabel(?InvoiceType $type = null): string
    {
        $type ??= $this->invoiceType();
        [$position, $count] = $this->rabPosition();
        $percent = rtrim(rtrim(number_format((float) $this->percentage, 2, ',', '.'), '0'), ',').'%';

        return match (true) {
            $type === InvoiceType::Termin => "Termin {$position} dari {$count} · {$percent}",
            $type === InvoiceType::Tambahan && $count > 1 => "{$type->label()} {$position} dari {$count} · {$percent}",
            default => $type->label(),
        };
    }
}
