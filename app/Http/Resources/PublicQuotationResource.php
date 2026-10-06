<?php

namespace App\Http\Resources;

use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\QuotationPaymentTerm;
use App\Models\SiteSetting;
use App\Services\QuotationService;
use App\Support\Letters\QuotationLetter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sprint 12 decision #14 — everything the client's public page may see,
 * as an explicit whitelist: company identity, the client's name and
 * address, the offer (type, number, version, dates), its sections and
 * items, totals and payment scheme. Never: ✔/✘ reviews, internal notes,
 * approvals, internal users, request notes, cost prices or IDs beyond the
 * quotation number. New fields must be added here on purpose.
 *
 * @mixin Quotation
 */
class PublicQuotationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Quotation $quotation */
        $quotation = $this->resource;
        $site = SiteSetting::current();

        return [
            'company' => [
                'name' => $site->site_name,
                'address' => $site->company_address,
                'phone' => $site->company_phone,
                'email' => $site->company_email,
            ],
            'client' => [
                'name' => $quotation->lead->client_name,
                'address' => $quotation->lead->address,
            ],
            'type' => $quotation->type->value,
            // Sprint 12 #29 — an addendum shows as such to the client.
            'typeLabel' => $quotation->title(),
            'number' => 'QUO-'.str_pad((string) $quotation->id, 5, '0', STR_PAD_LEFT),
            'version' => $quotation->version,
            'sentAt' => $quotation->sent_at?->toIso8601String(),
            'validUntil' => $quotation->valid_until?->toDateString(),
            'sections' => $quotation->rabGroups()->values()->map(fn (array $group) => [
                'name' => $group['name'],
                'subtotal' => $group['subtotal'],
                'items' => $group['items']->values()->map(fn (QuotationItem $item) => [
                    'description' => $item->description,
                    'dimLength' => $item->dim_length,
                    'dimWidthHeight' => $item->dim_width_height,
                    'qty' => (float) $item->qty,
                    'unit' => $item->unit?->code,
                    'unitPrice' => (float) $item->unit_price,
                    'total' => (float) $item->total_price,
                ])->all(),
            ])->all(),
            'itemsTotal' => (float) ($quotation->items_total ?? $quotation->total_amount),
            'discount' => (float) $quotation->discount_amount,
            'roundedTotal' => $quotation->rounded_total === null ? null : (float) $quotation->rounded_total,
            'total' => (float) $quotation->total_amount,
            'paymentTerms' => $quotation->paymentTerms->map(fn (QuotationPaymentTerm $term) => [
                'sequence' => $term->sequence,
                'label' => $term->label,
                'percentage' => (float) $term->percentage,
                'amount' => (float) $term->amount,
                'trigger' => $term->trigger->label(),
                'dueDate' => $term->due_date?->toDateString(),
                'milestone' => $term->milestone_name,
            ])->all(),
            'approvedAt' => $quotation->client_approved_at?->toIso8601String(),
            // Sprint 15 — the same company letter as the PDF (QuotationLetter):
            // client-facing text only — never the request note, references or reviews.
            'letter' => QuotationLetter::for($quotation, $site, QuotationService::VALIDITY_DAYS),
        ];
    }
}
