<?php

namespace App\Support\Letters;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\QuotationType;
use App\Models\Invoice;
use App\Models\QuotationItem;
use App\Models\SiteSetting;
use App\Support\Terbilang;

/**
 * Sprint 15 — an invoice as the company's letter (the user's sample is a
 * "Invoice Jasa Desain"). A Jasa Survey / Jasa Desain invoice bills the
 * whole RAB, so it repeats the RAB's lines; a DP / termin / pelunasan /
 * pekerjaan tambah invoice is one line for its share of the RAB.
 *
 * Expects `lead`, `quotation.items.unit`, `quotation.sections`, `termin` loaded.
 */
final class InvoiceLetter
{
    /** @return array<string, mixed> */
    public static function for(Invoice $invoice, SiteSetting $site): array
    {
        $quotation = $invoice->quotation;
        $client = $invoice->lead->client_name;
        $amount = (float) $invoice->amount;
        $isService = in_array($invoice->type, [InvoiceType::JasaSurvey, InvoiceType::JasaDesain], true);
        $job = $isService ? $invoice->type->label() : trim($invoice->type->label().($quotation ? ' '.QuotationLetter::job($quotation) : ''));

        if ($isService && $quotation && $quotation->items->isNotEmpty()) {
            $groups = $quotation->rabGroups()->values()->map(fn (array $group, int $index) => [
                'label' => $index < 26 ? chr(65 + $index) : (string) ($index + 1),
                'name' => $group['name'],
                'subtotal' => (float) $group['subtotal'],
                'rows' => $group['items']->values()->map(fn (QuotationItem $item, int $i) => QuotationLetter::row($i + 1, $item))->all(),
            ])->all();
        } else {
            $termin = $invoice->termin;
            $groups = [[
                'label' => 'A',
                'name' => 'Umum',
                'subtotal' => $amount,
                'rows' => [[
                    'no' => 1,
                    'description' => mb_strtoupper(trim($job.($termin ? " — termin {$termin->termin_number}".($termin->percentage ? ' ('.rtrim(rtrim(number_format((float) $termin->percentage, 2, ',', '.'), '0'), ',').'%)' : '') : ''))),
                    'p' => null,
                    't' => null,
                    'volume' => '1',
                    'unit' => 'LS',
                    'unitPrice' => $amount,
                    'total' => $amount,
                ]],
            ]];
        }

        $paid = $invoice->status === InvoiceStatus::Terverifikasi;
        $notes = [
            $paid
                ? "{$job} senilai Rp ".LetterParts::rupiah($amount).' ('.Terbilang::rupiah($amount).') telah LUNAS'.($invoice->paid_date ? ' pada '.$invoice->paid_date->locale('id')->translatedFormat('j F Y') : '').'.'
                : LetterParts::paymentLine($job, $amount, $site),
        ];
        if (! $paid && $invoice->due_date) {
            $notes[] = 'Mohon pembayaran paling lambat '.$invoice->due_date->locale('id')->translatedFormat('j F Y').'.';
        }
        if ($isService && $quotation) {
            $notes = [...$notes, ...LetterParts::lines($quotation->client_notes ?: $site->defaultNoteFor(($quotation->type ?? QuotationType::Proyek)->value))];
        }

        return [
            'kind' => 'INVOICE',
            'company' => LetterParts::company($site),
            'signer' => LetterParts::signer($site),
            'number' => $invoice->number,
            'draft' => false,
            'date' => LetterParts::date(($invoice->issued_at ?? now())->timezone('Asia/Jakarta')),
            'subject' => "Invoice {$job} {$client}",
            'recipient' => $client,
            'meta' => array_values(array_filter([
                $invoice->due_date ? ['label' => 'Jatuh Tempo', 'value' => $invoice->due_date->locale('id')->translatedFormat('j F Y')] : null,
                $quotation?->letter_number ? ['label' => 'Ref. Penawaran', 'value' => $quotation->letter_number] : null,
            ])),
            'intro' => 'Melalui surat ini kami uraikan tagihan biaya '.mb_strtolower($job).', dengan rincian sebagai berikut:',
            'showGroups' => count($groups) > 1,
            'groups' => $groups,
            'totals' => self::totals($invoice, $isService, $amount),
            'total' => $amount,
            'totalInWords' => Terbilang::rupiah($amount),
            'notes' => $notes,
            'paymentTerms' => [],
            'closing' => 'Demikian INVOICE ini kami sampaikan. Atas perhatiannya, kami mengucapkan terima kasih.',
            'stamp' => $paid ? 'LUNAS' : null,
        ];
    }

    /**
     * A service invoice repeats its RAB's lines, so it also shows the RAB's
     * discount / rounding before what is billed.
     *
     * @return list<array{label: string, amount: float}>
     */
    private static function totals(Invoice $invoice, bool $isService, float $amount): array
    {
        $quotation = $invoice->quotation;

        if (! $isService || ! $quotation || ((float) $quotation->discount_amount <= 0 && $quotation->rounded_total === null)) {
            return [['label' => 'TOTAL', 'amount' => $amount]];
        }

        return array_values(array_filter([
            ['label' => 'TOTAL', 'amount' => (float) ($quotation->items_total ?? $amount)],
            (float) $quotation->discount_amount > 0 ? ['label' => 'DISKON', 'amount' => -1 * (float) $quotation->discount_amount] : null,
            $quotation->rounded_total !== null ? ['label' => 'PEMBULATAN', 'amount' => (float) $quotation->rounded_total] : null,
            ['label' => 'TOTAL TAGIHAN', 'amount' => $amount],
        ]));
    }
}
