<?php

namespace App\Exports\CashFlow;

use App\Models\FinanceTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** "Per Bulan" — pemasukan/pengeluaran per month of the period, zero-filled, grouped in PHP (no SQL DATE_FORMAT). */
class MonthlySheet extends SummarySheet
{
    /**
     * @param  Collection<int, FinanceTransaction>  $rows  Pindah Dana already removed when company-level
     * @param  list<string>  $months  'Y-m', oldest first
     */
    public function __construct(
        private Collection $rows,
        private array $months,
        private bool $excludesTransfers,
    ) {}

    public function headings(): array
    {
        return ['Bulan', 'Pemasukan', 'Pengeluaran', 'Selisih'];
    }

    public function array(): array
    {
        $byMonth = $this->rows->groupBy(fn (FinanceTransaction $row) => $row->date->format('Y-m'));
        $lines = [];

        foreach ($this->months as $month) {
            [$income, $expense] = self::sums($byMonth->get($month, collect()));
            // `!` = day 1 — without it the 29th–31st overflow into the next month.
            $lines[] = [Carbon::createFromFormat('!Y-m', $month)->translatedFormat('F Y'), $income, $expense, round($income - $expense, 2)];
        }

        [$income, $expense] = self::sums($this->rows);
        $lines[] = ['Total', $income, $expense, round($income - $expense, 2)];

        if ($this->excludesTransfers) {
            $lines[] = [];
            $lines[] = [self::TRANSFERS_NOTE];
        }

        return $lines;
    }

    public function columnFormats(): array
    {
        return ['B' => self::MONEY, 'C' => self::MONEY, 'D' => self::MONEY];
    }

    public function title(): string
    {
        return 'Per Bulan';
    }
}
