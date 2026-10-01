<?php

namespace App\Exports\CashFlow;

use App\Models\FinanceTransaction;
use Illuminate\Support\Collection;

/** "Per Proyek" — pemasukan/pengeluaran per project; rows without one grouped last as "Tanpa Proyek". */
class ProjectSheet extends SummarySheet
{
    private const NO_PROJECT = 'Tanpa Proyek';

    /** @param  Collection<int, FinanceTransaction>  $rows  Pindah Dana already removed when company-level */
    public function __construct(
        private Collection $rows,
        private bool $excludesTransfers,
    ) {}

    public function headings(): array
    {
        return ['Proyek', 'Pemasukan', 'Pengeluaran', 'Selisih'];
    }

    public function array(): array
    {
        $lines = $this->rows
            ->groupBy(fn (FinanceTransaction $row) => $row->project_id ?? 0)
            ->map(function (Collection $projectRows, int $projectId) {
                [$income, $expense] = self::sums($projectRows);

                return [
                    $projectId === 0 ? self::NO_PROJECT : ($projectRows->first()->project->name ?? "Proyek #{$projectId}"),
                    $income,
                    $expense,
                    round($income - $expense, 2),
                ];
            })
            ->sortBy(fn (array $line) => [$line[0] === self::NO_PROJECT ? 1 : 0, mb_strtolower($line[0])])
            ->values()
            ->all();

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
        return 'Per Proyek';
    }
}
