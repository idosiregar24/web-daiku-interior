<?php

namespace App\Exports\CashFlow;

use App\Enums\FinanceTransactionType;
use App\Models\FinanceTransaction;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/** "Transaksi" — every filtered row, Pindah Dana legs included (each one really moved an account). */
class TransactionsSheet implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping, WithTitle
{
    /** @param  Collection<int, FinanceTransaction>  $rows */
    public function __construct(private Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['Tanggal', 'Proyek', 'Rekening', 'Jenis', 'Kategori', 'Deskripsi', 'Nominal'];
    }

    /** @param  FinanceTransaction  $transaction */
    public function map($transaction): array
    {
        return [
            $transaction->date->format('Y-m-d'),
            $transaction->project->name ?? '-',
            $transaction->bankAccount->label ?? '-',
            $transaction->type === FinanceTransactionType::Income ? 'Pemasukan' : 'Pengeluaran',
            $transaction->kategori?->label() ?? '-',
            $transaction->description,
            (float) $transaction->amount,
        ];
    }

    public function columnFormats(): array
    {
        return ['G' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1];
    }

    public function title(): string
    {
        return 'Transaksi';
    }
}
