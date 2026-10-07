<?php

namespace App\Models;

use App\Enums\PaymentTermTrigger;
use App\Enums\QuotationStatus;
use App\Enums\QuotationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

class Quotation extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'type',
        'lead_survey_id',
        'parent_quotation_id',
        'project_id',
        'items_total',
        'discount_amount',
        'rounded_total',
        'total_amount',
        'status',
        'valid_until',
        'first_sent_at',
        'sent_at',
        'client_approved_at',
        'client_approved_ip',
        'client_approved_user_agent',
        'client_approved_link_id',
        'version',
        'created_by',
        'requested_by',
        // Sprint 17 Sub 04 — self::VIA_DESIGN_ACC when asked for automatically, null = by a person.
        'requested_via',
        'request_note',
        'custom_name',
        // Sprint 15 — "377/OFF/Daiku/IX/2026", given when this version is sent; the RAB's "Catatan".
        'letter_number',
        'client_notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'type' => QuotationType::class,
            'items_total' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'rounded_total' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'valid_until' => 'date',
            'first_sent_at' => 'datetime',
            'sent_at' => 'datetime',
            'client_approved_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /** Sprint 12 #29 — an addendum's RAB Fix. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_quotation_id');
    }

    /** Sprint 12 #29 — the running project an addendum adds work to (null for any other quotation). */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** RAB Tambahan — a PROYEK quotation on top of a project's RAB Fix. */
    public function isAddendum(): bool
    {
        return $this->parent_quotation_id !== null;
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('sort_order');
    }

    /** Sprint 12 decision #11 — bagian pekerjaan, in order. */
    public function sections(): HasMany
    {
        return $this->hasMany(QuotationSection::class)->orderBy('sort_order');
    }

    /** Sprint 12 decision #12 — the DP/termin scheme the client approves with the RAB. */
    /** Sprint 12 #20 — invoices billed from this quotation. */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->orderBy('id');
    }

    /** Sprint 12 #13 — every link sent to the client, newest first. */
    public function shareLinks(): HasMany
    {
        return $this->hasMany(QuotationShareLink::class)->latest('id');
    }

    /** The link of the version on offer now, if it was sent. */
    public function currentShareLink(): ?QuotationShareLink
    {
        return $this->shareLinks()->where('version', $this->version)->first();
    }

    /** Sprint 12 #8 — ✔/✘ per item by PM / Asisten PM / CEO, every version. */
    public function itemReviews(): HasMany
    {
        return $this->hasMany(QuotationItemReview::class)->orderBy('id');
    }

    public function paymentTerms(): HasMany
    {
        return $this->hasMany(QuotationPaymentTerm::class)->orderBy('sequence');
    }

    /** Sprint 14 Sub 01 — links & photos handed over with the request (internal). */
    public function references(): HasMany
    {
        return $this->hasMany(QuotationReference::class)->orderBy('id');
    }

    /** Marketing who asked the Estimator for this RAB (Sprint 12 #7). */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** The outside-Pekanbaru survey a RAB Jasa Survey pays for. */
    public function leadSurvey(): BelongsTo
    {
        return $this->belongsTo(LeadSurvey::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(QuotationApproval::class)->latest('created_at');
    }

    /** Closed (rejected) versions, newest first — PRD §4.3 "Versi Revisi", see QuotationRevision. */
    public function revisions(): HasMany
    {
        return $this->hasMany(QuotationRevision::class)->orderByDesc('version');
    }

    public function scopeByStatus(Builder $query, ?string $status): Builder
    {
        return $query->when($status, fn (Builder $q) => $q->where('status', $status));
    }

    /** `CUSTOM` (Sprint 14) = the RAB Proyek given their own name; otherwise a QuotationType value. */
    public function scopeByType(Builder $query, ?string $type): Builder
    {
        return match ($type) {
            null, '' => $query,
            self::FILTER_CUSTOM => $query->whereNotNull('custom_name'),
            default => $query->where('type', $type),
        };
    }

    /** Quotation list filter value for the custom-named RAB (Sprint 14 Sub 02). */
    public const FILTER_CUSTOM = 'CUSTOM';

    /** Sprint 17 Sub 04 — `requested_via` of the RAB Proyek asked for by the design's client approval. */
    public const VIA_DESIGN_ACC = 'DESIGN_ACC';

    /**
     * Sprint 17 Sub 03 — a Jasa Survey / Jasa Desain RAB the client approved
     * whose invoice nobody issued yet (one invoice per service RAB, D4 —
     * InvoiceService::issueForQuotation()). With `$marketing`: only on that
     * Marketing's own leads, or on leads without a Marketing (same rule as
     * the termin-invoice queue) — read by the "Perlu Tindakan" queue and
     * the Quotation list's `awaiting_invoice` filter.
     */
    public function scopeAwaitingInvoice(Builder $query, ?User $marketing = null): Builder
    {
        return $query
            ->where('status', QuotationStatus::ClientApproved->value)
            ->whereDoesntHave('invoices')
            ->where(fn (Builder $kind) => $kind
                ->whereIn('type', [QuotationType::Survey->value, QuotationType::Desain->value])
                // Sprint 17 Sub 06 (K2) — a RAB Proyek's DP is billed right away, before the CEO opens the project.
                ->orWhere(fn (Builder $project) => $project->billableUpfront()))
            ->when($marketing, fn (Builder $q) => $q->whereHas('lead', fn (Builder $lead) => $lead
                ->where(fn (Builder $owner) => $owner->where('assigned_to', $marketing->id)->orWhereNull('assigned_to'))));
    }

    /**
     * Sprint 17 Sub 06 (K2) — a main RAB Proyek (not a RAB Tambahan) whose
     * project isn't opened yet and whose scheme has a "di muka" payment:
     * its DP invoice can be issued now (InvoiceService::issueForQuotation()),
     * and is attached to the DP termin when the CEO opens the project
     * (TerminService::attachUpfrontInvoice()).
     */
    public function scopeBillableUpfront(Builder $query): Builder
    {
        return $query
            ->where('type', QuotationType::Proyek->value)
            ->whereNull('parent_quotation_id')
            ->whereDoesntHave('openedProject')
            ->whereHas('paymentTerms', fn (Builder $term) => $term->where('trigger', PaymentTermTrigger::DiMuka->value));
    }

    /** The scheme's first "di muka" row — what the early DP invoice bills. */
    public function upfrontTerm(): ?QuotationPaymentTerm
    {
        return $this->paymentTerms->first(fn (QuotationPaymentTerm $term) => $term->trigger === PaymentTermTrigger::DiMuka);
    }

    /** The project opened from this RAB Fix (`projects.quotation_id`), if the CEO opened it. */
    public function openedProject(): HasOne
    {
        return $this->hasOne(Project::class, 'quotation_id');
    }

    /**
     * Sprint 17 Sub 04 — the lead's RAB Proyek still running (QuotationService::request()
     * refuses a second one meanwhile), as the lead & design pages show it.
     * `pending_auto` = asked for automatically on the design's approval and
     * still waiting for / being drafted by the Estimator.
     *
     * @return array{id: int, title: string, status: string, requested_at: string|null, auto: bool, pending_auto: bool}|null
     */
    public static function runningProjectRabSummary(int $leadId): ?array
    {
        $running = self::query()
            ->where('lead_id', $leadId)
            ->where('type', QuotationType::Proyek->value)
            ->whereNotIn('status', QuotationStatus::closedValues())
            ->latest('id')
            ->first();

        if ($running === null) {
            return null;
        }

        $auto = $running->requested_via === self::VIA_DESIGN_ACC;

        return [
            'id' => $running->id,
            'title' => $running->title(),
            'status' => $running->status->value,
            'requested_at' => $running->created_at?->toIso8601String(),
            'auto' => $auto,
            'pending_auto' => $auto && in_array($running->status, [QuotationStatus::Diminta, QuotationStatus::Draft], true),
        ];
    }

    /**
     * What this RAB is called everywhere — page titles, notifications, PDF,
     * the client's link: its custom name (Sprint 14 "Buat RAB → Lainnya",
     * prefixed "RAB " when missing), else "RAB Tambahan" for an addendum,
     * else the type's label.
     */
    public function title(): string
    {
        $custom = trim((string) $this->custom_name);

        return match (true) {
            $custom !== '' => preg_match('/^rab\b/i', $custom) ? $custom : "RAB {$custom}",
            $this->isAddendum() => 'RAB Tambahan',
            default => ($this->type ?? QuotationType::Proyek)->label(),
        };
    }

    /**
     * Items grouped as the RAB prints them (PDF, Excel): items without a
     * section (pre-Sprint-12 RABs) first as "Umum", then each bagian
     * pekerjaan in order, with its subtotal. Expects `items` and
     * `sections` loaded.
     *
     * @return Collection<int, array{name: string, items: Collection<int, QuotationItem>, subtotal: float}>
     */
    public function rabGroups(): Collection
    {
        $bySection = $this->items->groupBy(fn (QuotationItem $item) => $item->section_id ?? 0);

        $groups = collect();

        if ($bySection->has(0)) {
            $groups->push(['name' => 'Umum', 'items' => $bySection->get(0)]);
        }

        foreach ($this->sections as $section) {
            if ($bySection->has($section->id)) {
                $groups->push(['name' => $section->name, 'items' => $bySection->get($section->id)]);
            }
        }

        return $groups->map(fn (array $group) => $group + [
            'subtotal' => $group['items']->sum(fn (QuotationItem $item) => (int) round((float) $item->total_price * 100)) / 100,
        ]);
    }
}
