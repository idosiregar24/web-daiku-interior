<?php

namespace App\Exports;

use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\QuotationPaymentTerm;
use App\Models\SiteSetting;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Sprint 12 decision #11 — the RAB as the Estimator's Excel lays it out:
 * per bagian pekerjaan (A, B, …) No · Item · P · T/L · Volume · Satuan ·
 * Harga · Subtotal, then Total / Diskon / Pembulatan and the DP/termin
 * scheme. Item text goes through SafeValueBinder (config/excel.php).
 */
class QuotationExport implements FromArray, ShouldAutoSize, WithStyles, WithTitle
{
    /** @var list<int> 1-based rows printed bold (headings, section names, totals). */
    private array $boldRows = [];

    /** @var list<int> rows whose G/H (or D, for the scheme) cells are money. */
    private array $moneyRows = [];

    /** @var list<int> payment-scheme rows — money sits in column D there. */
    private array $termRows = [];

    private ?int $headingRow = null;

    public function __construct(private Quotation $quotation)
    {
        $this->quotation->loadMissing(['lead:id,client_name', 'items', 'sections', 'paymentTerms']);
    }

    public function array(): array
    {
        $quotation = $this->quotation;
        $site = SiteSetting::current();

        $rows = [
            [$site->site_name ?? 'Daiku Interior'],
            [$quotation->type?->label() ?? 'RAB Proyek'],
            ['Klien', $quotation->lead->client_name],
            ['Nomor', 'QUO-'.str_pad((string) $quotation->id, 5, '0', STR_PAD_LEFT)." (versi {$quotation->version})"],
            ['Tanggal Cetak', now()->translatedFormat('d F Y')],
            [],
            ['No', 'Item', 'P', 'T/L', 'Volume', 'Satuan', 'Harga', 'Subtotal'],
        ];
        $this->boldRows = [1, 2];
        $this->headingRow = count($rows);

        foreach ($quotation->rabGroups()->values() as $index => $group) {
            $rows[] = [$index < 26 ? chr(65 + $index) : (string) ($index + 1), $group['name']];
            $this->boldRows[] = count($rows);

            foreach ($group['items']->values() as $number => $item) {
                /** @var QuotationItem $item */
                $rows[] = [
                    $number + 1,
                    $item->description,
                    $item->dim_length,
                    $item->dim_width_height,
                    (float) $item->qty,
                    $item->unit?->code,
                    (float) $item->unit_price,
                    (float) $item->total_price,
                ];
                $this->moneyRows[] = count($rows);
            }

            $rows[] = ['', "Subtotal {$group['name']}", '', '', '', '', '', $group['subtotal']];
            $this->boldRows[] = count($rows);
            $this->moneyRows[] = count($rows);
        }

        $rows[] = [];
        $rows[] = ['', 'Total', '', '', '', '', '', (float) ($quotation->items_total ?? $quotation->total_amount)];
        $this->moneyRows[] = count($rows);

        if ((float) $quotation->discount_amount > 0) {
            $rows[] = ['', 'Diskon', '', '', '', '', '', -1 * (float) $quotation->discount_amount];
            $this->moneyRows[] = count($rows);
        }

        if ($quotation->rounded_total !== null) {
            $rows[] = ['', 'Pembulatan', '', '', '', '', '', (float) $quotation->rounded_total];
            $this->moneyRows[] = count($rows);
        }

        $rows[] = ['', 'GRAND TOTAL', '', '', '', '', '', (float) $quotation->total_amount];
        $this->boldRows[] = count($rows);
        $this->moneyRows[] = count($rows);

        if ($quotation->paymentTerms->isNotEmpty()) {
            $rows[] = [];
            $rows[] = ['Skema Pembayaran'];
            $this->boldRows[] = count($rows);
            $rows[] = ['No', 'Termin', '%', 'Nominal', 'Pemicu'];
            $this->boldRows[] = count($rows);

            foreach ($quotation->paymentTerms as $term) {
                /** @var QuotationPaymentTerm $term */
                $rows[] = [
                    $term->sequence,
                    $term->label,
                    (float) $term->percentage,
                    (float) $term->amount,
                    $this->triggerText($term),
                ];
                $this->termRows[] = count($rows);
            }
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        foreach ($this->moneyRows as $row) {
            $sheet->getStyle("G{$row}:H{$row}")->getNumberFormat()->setFormatCode('#,##0');
        }

        foreach ($this->termRows as $row) {
            $sheet->getStyle("D{$row}")->getNumberFormat()->setFormatCode('#,##0');
        }

        $styles = array_fill_keys($this->boldRows, ['font' => ['bold' => true]]);
        $styles[1] = ['font' => ['bold' => true, 'size' => 14]];

        if ($this->headingRow !== null) {
            $styles[$this->headingRow] = [
                'font' => ['bold' => true],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FFF6D9']],
            ];
        }

        return $styles;
    }

    public function title(): string
    {
        return 'RAB';
    }

    private function triggerText(QuotationPaymentTerm $term): string
    {
        return $term->trigger->label().match (true) {
            $term->due_date !== null => ' — '.$term->due_date->translatedFormat('d F Y'),
            filled($term->milestone_name) => " — {$term->milestone_name}",
            default => '',
        };
    }
}
