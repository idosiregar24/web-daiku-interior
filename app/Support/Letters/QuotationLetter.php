<?php

namespace App\Support\Letters;

use App\Enums\QuotationType;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\QuotationPaymentTerm;
use App\Models\SiteSetting;
use App\Support\Quantity;
use App\Support\Terbilang;

/**
 * Sprint 15 — an offer (RAB) as the company's letter, in the format the
 * user handed over: number, perihal, kepada, the RAB table (Uraian, Ukuran
 * P/T, Volume, Satuan, harga satuan, harga), total in words, numbered
 * "Catatan", signature. The PDF (pdf/layouts/letter) and the client's link
 * page (PublicQuotationResource) render this same array.
 *
 * Expects `lead`, `items.unit`, `sections`, `paymentTerms` loaded.
 */
final class QuotationLetter
{
    /** @return array<string, mixed> */
    public static function for(Quotation $quotation, SiteSetting $site, int $validityDays): array
    {
        $job = self::job($quotation);
        $client = $quotation->lead->client_name;
        $groups = $quotation->rabGroups()->values();
        $terms = $quotation->paymentTerms;
        $total = (float) $quotation->total_amount;

        $totals = [['label' => 'TOTAL', 'amount' => (float) ($quotation->items_total ?? $total)]];
        if ((float) $quotation->discount_amount > 0) {
            $totals[] = ['label' => 'DISKON', 'amount' => -1 * (float) $quotation->discount_amount];
        }
        if ($quotation->rounded_total !== null) {
            $totals[] = ['label' => 'PEMBULATAN', 'amount' => (float) $quotation->rounded_total];
        }
        if (count($totals) > 1) {
            $totals[] = ['label' => 'GRAND TOTAL', 'amount' => $total];
        }

        // One payment row = paid in full (Jasa Survey / Desain); several = the DP/termin scheme below.
        $notes = [LetterParts::paymentLine($terms->count() > 1 ? "Nilai {$job}" : $job, $total, $site)];
        if ($terms->count() > 1) {
            $notes[] = 'Pembayaran mengikuti skema termin pada tabel di bawah.';
        }
        $notes = [...$notes, ...LetterParts::lines($quotation->client_notes ?: $site->defaultNoteFor(($quotation->type ?? QuotationType::Proyek)->value))];
        $notes[] = $quotation->valid_until
            ? 'Penawaran ini berlaku sampai '.$quotation->valid_until->locale('id')->translatedFormat('j F Y').'.'
            : "Penawaran ini berlaku {$validityDays} hari sejak dikirim.";

        return [
            'kind' => 'PENAWARAN',
            'company' => LetterParts::company($site),
            'signer' => LetterParts::signer($site),
            'number' => $quotation->letter_number,
            // Not sent yet: no number, marked as a draft.
            'draft' => $quotation->letter_number === null,
            'date' => LetterParts::date(($quotation->sent_at ?? now())->timezone('Asia/Jakarta')),
            'subject' => "Penawaran {$job} {$client}",
            'recipient' => $client,
            'meta' => [[
                'label' => 'Berlaku Sampai',
                'value' => $quotation->valid_until?->locale('id')->translatedFormat('j F Y') ?? "{$validityDays} hari sejak penawaran dikirim",
            ]],
            'intro' => 'Melalui surat ini kami sampaikan penawaran biaya '.mb_strtolower($job).', dengan rincian sebagai berikut:',
            'showGroups' => $groups->count() > 1 || ($groups->first()['name'] ?? 'Umum') !== 'Umum',
            'groups' => $groups->map(fn (array $group, int $index) => [
                'label' => $index < 26 ? chr(65 + $index) : (string) ($index + 1),
                'name' => $group['name'],
                'subtotal' => (float) $group['subtotal'],
                'rows' => $group['items']->values()->map(fn (QuotationItem $item, int $i) => self::row($i + 1, $item))->all(),
            ])->all(),
            'totals' => $totals,
            'total' => $total,
            'totalInWords' => Terbilang::rupiah($total),
            'notes' => $notes,
            'paymentTerms' => $terms->count() > 1 ? $terms->map(fn (QuotationPaymentTerm $term) => [
                'sequence' => $term->sequence,
                'label' => $term->label,
                'percentage' => rtrim(rtrim(number_format((float) $term->percentage, 2, ',', '.'), '0'), ','),
                'amount' => (float) $term->amount,
                'when' => trim($term->trigger->label()
                    .($term->due_date ? ' — '.$term->due_date->locale('id')->translatedFormat('j F Y') : '')
                    .($term->milestone_name ? ' — '.$term->milestone_name : '')),
            ])->values()->all() : [],
            'closing' => 'Demikian penawaran ini kami sampaikan. Atas perhatiannya, kami mengucapkan terima kasih.',
            'stamp' => null,
        ];
    }

    /** "Jasa Desain", "Proyek", "Renovasi Pagar", "Tambahan" — the RAB's title without "RAB". */
    public static function job(Quotation $quotation): string
    {
        return preg_replace('/^RAB\s+/i', '', $quotation->title()) ?: $quotation->title();
    }

    /** @return array<string, mixed> */
    public static function row(int $no, QuotationItem $item): array
    {
        return [
            'no' => $no,
            'description' => $item->description,
            'p' => $item->dim_length !== null ? Quantity::format($item->dim_length) : null,
            't' => $item->dim_width_height !== null ? Quantity::format($item->dim_width_height) : null,
            'volume' => Quantity::format($item->qty),
            'unit' => $item->unit?->code,
            'unitPrice' => (float) $item->unit_price,
            'total' => (float) $item->total_price,
        ];
    }
}
