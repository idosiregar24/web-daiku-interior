<?php

namespace App\Support\Letters;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\TerminStatus;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\SiteSetting;
use App\Models\Termin;
use App\Support\Phone;
use App\Support\Terbilang;
use Illuminate\Support\Carbon;

/**
 * Sprint 17 Sub 05 (K3) — an invoice is a bill, not a second offer. Same
 * letterhead, footer and signer as the offer (LetterParts), but its own
 * layout (pdf/layouts/invoice): a big INVOICE title, "Ditagihkan kepada"
 * next to the invoice data, a SHORT table (one row per RAB group for a Jasa
 * Survey / Jasa Desain invoice, one row for a termin — the item breakdown
 * stays in the referenced offer), the Total Tagihan box, "Cara Pembayaran"
 * and a status stamp. PDF only — there is no web invoice page.
 *
 * Expects `lead.city`, `project`, `quotation.items`, `quotation.sections`,
 * `termin.paymentTerm` loaded (missing ones lazy-load).
 */
final class InvoiceLetter
{
    /** What an invoice PDF loads (InvoiceController / TerminController::exportPdf()). */
    public const RELATIONS = [
        'lead:id,client_name,phone,email,address,city_id',
        'lead.city:id,name',
        'project:id,name',
        'quotation',
        'quotation.items',
        'quotation.sections',
        'termin.paymentTerm',
    ];

    /** @return array<string, mixed> */
    public static function for(Invoice $invoice, SiteSetting $site): array
    {
        $quotation = $invoice->quotation;
        $termin = $invoice->termin;
        $amount = (float) $invoice->amount;
        $isService = in_array($invoice->type, [InvoiceType::JasaSurvey, InvoiceType::JasaDesain], true);
        $job = $isService ? $invoice->type->label() : trim($invoice->type->label().($quotation ? ' '.QuotationLetter::job($quotation) : ''));
        $paid = $invoice->status === InvoiceStatus::Terverifikasi;

        [$rows, $adjustments] = match (true) {
            $isService && $quotation && $quotation->items->isNotEmpty() => self::serviceRows($quotation, $job),
            $termin !== null => self::terminRows($termin, $quotation, $amount),
            default => [[self::row(1, $job, null, $amount)], []],
        };

        $notes = [];
        if ($quotation?->letter_number) {
            $notes[] = $isService
                ? "Rincian item pekerjaan sesuai Penawaran No. {$quotation->letter_number}."
                : "Nilai tagihan mengikuti skema pembayaran pada Penawaran No. {$quotation->letter_number}.";
        }
        $notes[] = match ($invoice->status) {
            InvoiceStatus::Terverifikasi => 'Pembayaran telah kami terima'.($invoice->paid_date ? ' pada '.self::day($invoice->paid_date) : '').'. Terima kasih.',
            InvoiceStatus::MenungguVerifikasi => 'Bukti pembayaran sudah kami terima dan sedang diverifikasi.',
            InvoiceStatus::Diterbitkan => $invoice->due_date ? 'Mohon pembayaran paling lambat '.self::day($invoice->due_date).'.' : null,
        };

        return self::layout($site, $invoice->lead, [
            'number' => $invoice->number,
            'draft' => false,
            'subject' => "Invoice {$job} {$invoice->lead->client_name}",
            'meta' => [
                ['label' => 'No. Invoice', 'value' => $invoice->number],
                ['label' => 'Tanggal', 'value' => self::day(($invoice->issued_at ?? now())->timezone('Asia/Jakarta'))],
                $invoice->due_date ? ['label' => 'Jatuh Tempo', 'value' => self::day($invoice->due_date)] : null,
                $quotation?->letter_number ? ['label' => 'Ref. Penawaran', 'value' => $quotation->letter_number] : null,
                $invoice->project ? ['label' => 'Proyek', 'value' => $invoice->project->name] : null,
                $termin ? ['label' => 'Termin', 'value' => "Termin ke-{$termin->termin_number}"] : null,
            ],
            'rows' => $rows,
            'adjustments' => $adjustments,
            'total' => $amount,
            'instruction' => "Cantumkan No. Invoice {$invoice->number} pada berita transfer.",
            'notes' => $notes,
            'status' => match ($invoice->status) {
                InvoiceStatus::Diterbitkan => ['label' => 'BELUM DIBAYAR', 'tone' => 'muted'],
                InvoiceStatus::MenungguVerifikasi => ['label' => 'MENUNGGU VERIFIKASI', 'tone' => 'warning'],
                InvoiceStatus::Terverifikasi => ['label' => 'LUNAS', 'tone' => 'success'],
            },
            'paid' => $paid,
        ]);
    }

    /**
     * A termin Marketing hasn't invoiced yet (Keuangan → Termin's PDF): the
     * same bill for what is still owed, marked DRAF — its number only comes
     * from LetterNumberService once the invoice is issued.
     *
     * Expects `project.lead.city`, `project.quotation`, `quotation`, `paymentTerm` loaded.
     *
     * @return array<string, mixed>
     */
    public static function forTermin(Termin $termin, SiteSetting $site): array
    {
        $project = $termin->project;
        $quotation = $termin->quotation ?? $project->quotation;
        $owed = (float) $termin->sisa_piutang;
        $paid = $termin->status === TerminStatus::Paid;
        [$rows, $adjustments] = self::terminRows($termin, $quotation, $owed);

        return self::layout($site, $project->lead, [
            'number' => null,
            'draft' => true,
            'subject' => "Tagihan Termin {$termin->termin_number} {$project->name}",
            'meta' => [
                ['label' => 'Tanggal', 'value' => self::day(now('Asia/Jakarta'))],
                $termin->scheduled_date ? ['label' => 'Jatuh Tempo', 'value' => self::day($termin->scheduled_date)] : null,
                $quotation?->letter_number ? ['label' => 'Ref. Penawaran', 'value' => $quotation->letter_number] : null,
                ['label' => 'Proyek', 'value' => $project->name],
                ['label' => 'Termin', 'value' => "Termin ke-{$termin->termin_number}"],
            ],
            'rows' => $rows,
            'adjustments' => $adjustments,
            'total' => $owed,
            'instruction' => "Cantumkan nama proyek dan termin ke-{$termin->termin_number} pada berita transfer.",
            'notes' => [
                $quotation?->letter_number ? "Nilai tagihan mengikuti skema pembayaran pada Penawaran No. {$quotation->letter_number}." : null,
                $paid ? 'Pembayaran termin ini telah kami terima. Terima kasih.' : null,
            ],
            'status' => match (true) {
                $paid => ['label' => 'LUNAS', 'tone' => 'success'],
                $termin->isPartiallyPaid() => ['label' => 'DIBAYAR SEBAGIAN', 'tone' => 'warning'],
                default => ['label' => 'BELUM DIBAYAR', 'tone' => 'muted'],
            },
            'paid' => $paid,
        ]);
    }

    /**
     * "Termin 1 — DP 30% dari RAB Proyek 5/OFF/Daiku/X/2026".
     */
    public static function terminDescription(Termin $termin, ?Quotation $quotation): string
    {
        $label = trim((string) $termin->paymentTerm?->label);
        $share = trim(implode(' ', array_filter([
            // A scheme row named "Termin 2" would read "Termin 2 — Termin 2 40%".
            preg_match('/^termin\b/i', $label) ? null : $label,
            $termin->percentage !== null ? self::percent($termin->percentage).'%' : null,
        ])));
        $of = $quotation ? trim($quotation->title().' '.$quotation->letter_number) : '';

        return "Termin {$termin->termin_number}"
            .($share !== '' ? " — {$share}" : '')
            .($of !== '' ? ($share !== '' ? " dari {$of}" : " — {$of}") : '');
    }

    /**
     * The parts both bills share: company, signer, bill-to, total in words,
     * payment box, closing.
     *
     * @param  array<string, mixed>  $bill
     * @return array<string, mixed>
     */
    private static function layout(SiteSetting $site, Lead $lead, array $bill): array
    {
        return [
            'kind' => 'INVOICE',
            'company' => LetterParts::company($site),
            'signer' => LetterParts::signer($site),
            'number' => $bill['number'],
            'draft' => $bill['draft'],
            'subject' => $bill['subject'],
            'recipient' => $lead->client_name,
            'billTo' => self::billTo($lead),
            'meta' => array_values(array_filter($bill['meta'])),
            'summaryRows' => $bill['rows'],
            'adjustments' => $bill['adjustments'],
            'total' => $bill['total'],
            'totalInWords' => Terbilang::rupiah($bill['total']),
            'paymentInfo' => [
                'accounts' => LetterParts::bankAccounts(),
                'holder' => $site->company_legal_name ?: null,
                'instruction' => $bill['instruction'],
            ],
            'status' => $bill['status'],
            'paid' => $bill['paid'],
            'notes' => array_values(array_filter($bill['notes'])),
            'closing' => 'Atas kepercayaan dan kerja samanya, kami mengucapkan terima kasih.',
        ];
    }

    /** @return array{name: string, contacts: list<string>, address: string|null} */
    private static function billTo(Lead $lead): array
    {
        $address = implode(', ', array_filter([trim((string) $lead->address), $lead->city?->name]));

        return [
            'name' => $lead->client_name,
            'contacts' => array_values(array_filter([Phone::format($lead->phone), $lead->email])),
            'address' => $address !== '' ? $address : null,
        ];
    }

    /**
     * One row per RAB group (a single "Umum" group reads as the job itself),
     * then the RAB's discount / rounding so the rows add up to what is billed.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array{label: string, amount: float}>}
     */
    private static function serviceRows(Quotation $quotation, string $job): array
    {
        $groups = $quotation->rabGroups()->values();
        $single = $groups->count() === 1 && $groups->first()['name'] === 'Umum';

        // The items themselves stay in the referenced offer (see the invoice's "Catatan").
        $rows = $groups->map(fn (array $group, int $index) => self::row(
            $index + 1,
            $single ? $job : $group['name'],
            $group['items']->count().' item pekerjaan',
            (float) $group['subtotal'],
        ))->all();

        $discount = (float) $quotation->discount_amount;
        if ($discount <= 0 && $quotation->rounded_total === null) {
            return [$rows, []];
        }

        // In hundredths, so the rounding line is exact.
        $itemsCents = (int) round($groups->sum('subtotal') * 100);
        $discountCents = (int) round($discount * 100);
        $roundingCents = $quotation->rounded_total !== null
            ? (int) round((float) $quotation->rounded_total * 100) - ($itemsCents - $discountCents)
            : 0;

        return [$rows, array_values(array_filter([
            ['label' => 'Subtotal', 'amount' => $itemsCents / 100],
            $discountCents > 0 ? ['label' => 'Diskon', 'amount' => -$discountCents / 100] : null,
            $roundingCents !== 0 ? ['label' => 'Pembulatan', 'amount' => $roundingCents / 100] : null,
        ]))];
    }

    /**
     * The termin's own row at its full value; what was already paid on it
     * comes off before the total.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array{label: string, amount: float}>}
     */
    private static function terminRows(Termin $termin, ?Quotation $quotation, float $billed): array
    {
        $full = (float) $termin->amount;
        $rows = [self::row(1, self::terminDescription($termin, $quotation), $termin->milestone_name ? "Milestone: {$termin->milestone_name}" : null, $full)];
        $paidCents = (int) round($full * 100) - (int) round($billed * 100);

        return [$rows, $paidCents > 0 ? [
            ['label' => 'Subtotal', 'amount' => $full],
            ['label' => 'Dikurangi pembayaran diterima', 'amount' => -$paidCents / 100],
        ] : []];
    }

    /** @return array{no: int, description: string, detail: string|null, amount: float} */
    private static function row(int $no, string $description, ?string $detail, float $amount): array
    {
        return ['no' => $no, 'description' => $description, 'detail' => $detail, 'amount' => $amount];
    }

    /** "30.00" → "30", "12.50" → "12,5". */
    private static function percent(float|string $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
    }

    /** "1 Oktober 2026" */
    private static function day(Carbon $date): string
    {
        return $date->copy()->locale('id')->translatedFormat('j F Y');
    }
}
