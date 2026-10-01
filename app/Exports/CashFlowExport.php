<?php

namespace App\Exports;

use App\Enums\FinanceCategory;
use App\Exports\CashFlow\AccountSheet;
use App\Exports\CashFlow\MonthlySheet;
use App\Exports\CashFlow\ProjectSheet;
use App\Exports\CashFlow\TransactionsSheet;
use App\Models\FinanceTransaction;
use App\Services\FinanceTransactionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * PRD §4.7 "Export Excel: Laporan cash flow per bulan, per proyek, per
 * rekening" — one workbook over the same filtered rows as the
 * Transactions page (FinanceTransaction::scopeFilter()): "Transaksi"
 * (detail), "Per Bulan", "Per Proyek", "Per Rekening". Without a date
 * filter it covers the last 6 months, current month included — the
 * range of the Finance Dashboard chart and this export's original
 * behaviour. Per Bulan/Per Proyek leave Pindah Dana out when the export
 * is company-level (FinanceTransactionService::isCompanyLevel()); Per
 * Rekening always counts both legs.
 */
class CashFlowExport implements WithMultipleSheets
{
    public const DEFAULT_MONTHS = 6;

    /** @var array<string, mixed> */
    private array $filters;

    /**
     * @param  array{type?: ?string, project_id?: int|string|null, bank_account_id?: int|string|null, kategori?: ?string, from?: ?string, to?: ?string}  $filters
     */
    public function __construct(array $filters = [])
    {
        if (empty($filters['from']) && empty($filters['to'])) {
            $filters['from'] = now()->startOfMonth()->subMonths(self::DEFAULT_MONTHS - 1)->toDateString();
        }

        $this->filters = $filters;
    }

    /** The filters actually applied (defaults included) — for the sheets and tests. */
    public function filters(): array
    {
        return $this->filters;
    }

    public function sheets(): array
    {
        $rows = FinanceTransaction::query()
            ->with(['project:id,name', 'bankAccount:id,label'])
            ->filter($this->filters)
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $companyLevel = FinanceTransactionService::isCompanyLevel($this->filters);
        $summaryRows = $companyLevel
            ? $rows->reject(fn (FinanceTransaction $row) => $row->kategori === FinanceCategory::PindahDana)->values()
            : $rows;

        return [
            new TransactionsSheet($rows),
            new MonthlySheet($summaryRows, $this->months($rows), $companyLevel),
            new ProjectSheet($summaryRows, $companyLevel),
            new AccountSheet($rows, $this->filters),
        ];
    }

    /**
     * Every month of the period, zero-filled: from the `from` filter (or
     * the first row) to the `to` filter (or today / the last row, if it's
     * dated later).
     *
     * @param  Collection<int, FinanceTransaction>  $rows
     * @return list<string> 'Y-m'
     */
    private function months(Collection $rows): array
    {
        $first = $this->filters['from'] ?? $rows->first()?->date?->toDateString();

        if ($first === null) {
            return [];
        }

        $last = $this->filters['to']
            ?? max(now()->toDateString(), (string) $rows->last()?->date?->toDateString());

        $months = [];
        $cursor = Carbon::parse($first)->startOfMonth();
        $end = Carbon::parse($last)->startOfMonth();

        while ($cursor->lte($end)) {
            $months[] = $cursor->format('Y-m');
            $cursor->addMonthNoOverflow();
        }

        return $months;
    }
}
