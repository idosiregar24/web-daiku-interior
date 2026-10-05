<?php

namespace App\Models;

use App\Enums\QuotationStatus;
use App\Enums\QuotationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Quotation extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'type',
        'lead_survey_id',
        'parent_quotation_id',
        'items_total',
        'discount_amount',
        'rounded_total',
        'total_amount',
        'status',
        'valid_until',
        'first_sent_at',
        'sent_at',
        'version',
        'created_by',
        'requested_by',
        'request_note',
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
            'version' => 'integer',
        ];
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
    /** Sprint 12 #8 — ✔/✘ per item by PM / Asisten PM / CEO, every version. */
    public function itemReviews(): HasMany
    {
        return $this->hasMany(QuotationItemReview::class)->orderBy('id');
    }

    public function paymentTerms(): HasMany
    {
        return $this->hasMany(QuotationPaymentTerm::class)->orderBy('sequence');
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

    public function scopeByType(Builder $query, ?string $type): Builder
    {
        return $query->when($type, fn (Builder $q) => $q->where('type', $type));
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
