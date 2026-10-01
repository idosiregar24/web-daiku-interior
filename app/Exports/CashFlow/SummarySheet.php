<?php

namespace App\Exports\CashFlow;

use App\Enums\FinanceTransactionType;
use App\Models\FinanceTransaction;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Shared shape of CashFlowExport's summary sheets: one line per group
 * (month / project / account), a total line, optionally a note.
 * WithStrictNullComparison keeps zero-filled months as 0 instead of
 * empty cells.
 */
abstract class SummarySheet implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStrictNullComparison, WithTitle
{
    protected const MONEY = NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1;

    protected const TRANSFERS_NOTE = 'Pindah Dana antar rekening tidak dihitung (uang tetap di perusahaan).';

    /**
     * @param  Collection<int, FinanceTransaction>  $rows
     * @return array{0: float, 1: float} [pemasukan, pengeluaran]
     */
    protected static function sums(Collection $rows): array
    {
        return [
            round((float) $rows->where('type', FinanceTransactionType::Income)->sum('amount'), 2),
            round((float) $rows->where('type', FinanceTransactionType::Expense)->sum('amount'), 2),
        ];
    }
}
