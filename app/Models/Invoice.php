<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Sprint 12 decisions #20–#21 — issued by Marketing, verified by Finance.
 * Every status change goes through App\Services\InvoiceService; finance
 * data, so never deleted.
 */
class Invoice extends Model
{
    /**
     * Sprint 23 K12/K12b — `append(Invoice::PAYMENT_LABELS)` sends the
     * payment labels below to the frontend (eager-load termin, project and
     * lead first — Invoice::withPaymentLabelRelations()).
     */
    public const PAYMENT_LABELS = ['payment_type_label', 'payment_label', 'status_label'];

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
        'payment_note',
        'proof_attempt',
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
            'proof_attempt' => 'integer',
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

    /**
     * Sprint 23 K12d — the proof photos of every "Tandai Klien Sudah Bayar"
     * (newest submission first); the current one's are `attempt = proof_attempt`.
     */
    public function paymentProofs(): HasMany
    {
        return $this->hasMany(InvoicePaymentProof::class)->orderByDesc('attempt')->orderBy('id');
    }

    /**
     * Sprint 23 K12 — what this invoice bills, as Finance reads it: "DP",
     * "Termin 2 dari 4 · 30%", "Pelunasan", "Jasa Desain", "Jasa Survey",
     * "Pekerjaan Tambahan". Termin position/percent come from the termin
     * (eager-load it with Termin::withRabPosition() on lists).
     */
    public function paymentTypeLabel(): string
    {
        if (in_array($this->type, [InvoiceType::Termin, InvoiceType::Tambahan], true) && $this->termin_id !== null && $this->termin !== null) {
            return $this->termin->paymentLabel($this->type);
        }

        return $this->type->label();
    }

    /** "Proyek Kopi Oz" — or the client's name while there is no project yet (Jasa Survey/Desain, an upfront DP). */
    public function paymentSubject(): string
    {
        $project = trim((string) $this->project?->name);

        if ($project !== '') {
            return Str::startsWith(Str::lower($project), 'proyek') ? $project : "Proyek {$project}";
        }

        return $this->lead?->client_name ?? $this->number;
    }

    /** "DP · Proyek Kopi Oz" — the one invoice label (K12/K12b), built here only. */
    public function paymentLabel(): string
    {
        return $this->paymentTypeLabel().' · '.$this->paymentSubject();
    }

    /**
     * Sprint 23 K12b — "Menunggu Konfirmasi Pembayaran DP · Proyek Kopi Oz":
     * the MENUNGGU_VERIFIKASI status, the Finance notification title, the
     * "Perlu Tindakan" item and the Verifikasi dialog title (the holder
     * "· Finance" is added by the status chip / is implied elsewhere).
     */
    public function confirmationLabel(): string
    {
        return 'Menunggu Konfirmasi Pembayaran '.$this->paymentLabel();
    }

    public function getPaymentTypeLabelAttribute(): string
    {
        return $this->paymentTypeLabel();
    }

    public function getPaymentLabelAttribute(): string
    {
        return $this->paymentLabel();
    }

    /** The status text when it differs from the domain wording — only MENUNGGU_VERIFIKASI (K12b). */
    public function getStatusLabelAttribute(): ?string
    {
        return $this->status === InvoiceStatus::MenungguVerifikasi ? $this->confirmationLabel() : null;
    }

    /**
     * Sprint 23 K12 — what paymentLabel() reads, without N+1: the client
     * and project names and the termin with its place in its RAB's scheme.
     */
    public function scopeWithPaymentLabelRelations(Builder $query): Builder
    {
        return $query->with([
            'lead:id,client_name',
            'project:id,name',
            'termin' => fn ($termin) => $termin->select(['id', 'project_id', 'quotation_id', 'termin_number', 'percentage'])->withRabPosition(),
        ]);
    }

    public function scopeByStatus(Builder $query, ?string $status): Builder
    {
        return $query->when($status, fn (Builder $q) => $q->where('status', $status));
    }

    public function scopeByType(Builder $query, ?string $type): Builder
    {
        return $query->when($type, fn (Builder $q) => $q->where('type', $type));
    }

    /**
     * Sprint 17 Sub 07 — issued, the client's payment proof not sent yet
     * (or sent back by Finance: reject() returns it to DITERBITKAN with a
     * reason). With `$marketing`: only that Marketing's own leads, or leads
     * without one — the "Perlu Tindakan" queue and the invoice list's
     * `awaiting_proof` filter.
     */
    public function scopeAwaitingProof(Builder $query, ?User $marketing = null): Builder
    {
        return $query
            ->where('status', InvoiceStatus::Diterbitkan->value)
            ->when($marketing, fn (Builder $q) => $q->whereHas('lead', fn (Builder $lead) => $lead
                ->where(fn (Builder $owner) => $owner->where('assigned_to', $marketing->id)->orWhereNull('assigned_to'))));
    }
}
