<?php

namespace App\Exports\CashFlow;

use App\Enums\FinanceCategory;
use App\Models\BankAccount;
use App\Models\FinanceTransaction;
use App\Services\FinanceTransactionService;
use Illuminate\Support\Collection;

/**
 * "Per Rekening" — pemasukan/pengeluaran per account, Pindah Dana legs
 * included (for one account they are real money in/out). When the rows
 * are every movement of the period (no jenis/proyek/kategori filter) the
 * sheet reads like a bank statement summary: Saldo Awal Periode (saldo
 * awal + everything before the period) → masuk/keluar → Saldo Akhir
 * Periode, listing every active account even without movement. The
 * "Keseluruhan" line's masuk/keluar are company-level (Pindah Dana left
 * out), its saldo columns the sum of the accounts.
 */
class AccountSheet extends SummarySheet
{
    /**
     * @param  Collection<int, FinanceTransaction>  $rows
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        private Collection $rows,
        private array $filters,
    ) {}

    private function showsBalances(): bool
    {
        return empty($this->filters['type']) && empty($this->filters['project_id']) && empty($this->filters['kategori']);
    }

    public function headings(): array
    {
        return $this->showsBalances()
            ? ['Rekening', 'Bank', 'No. Rekening', 'Saldo Awal Periode', 'Pemasukan', 'Pengeluaran', 'Saldo Akhir Periode']
            : ['Rekening', 'Bank', 'No. Rekening', 'Pemasukan', 'Pengeluaran', 'Selisih'];
    }

    public function array(): array
    {
        $withBalances = $this->showsBalances();
        $byAccount = $this->rows->groupBy(fn (FinanceTransaction $row) => $row->bank_account_id ?? 0);

        $accounts = BankAccount::query()
            ->when($this->filters['bank_account_id'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->orderBy('label')
            ->get();

        $opening = $withBalances
            ? app(FinanceTransactionService::class)->balancesAt($accounts, $this->filters['from'] ?? null)
            : [];

        $lines = [];
        $openingTotal = 0.0;
        $closingTotal = 0.0;

        foreach ($accounts as $account) {
            $accountRows = $byAccount->get($account->id, collect());
            $startBalance = $opening[$account->id] ?? 0.0;

            if ($accountRows->isEmpty() && ! ($withBalances && ($account->is_active || abs($startBalance) >= 0.01))) {
                continue;
            }

            [$income, $expense] = self::sums($accountRows);
            $endBalance = round($startBalance + $income - $expense, 2);
            $openingTotal += $startBalance;
            $closingTotal += $endBalance;

            $lines[] = $withBalances
                ? [$account->label, $account->bank_name, $account->account_no, $startBalance, $income, $expense, $endBalance]
                : [$account->label, $account->bank_name, $account->account_no, $income, $expense, round($income - $expense, 2)];
        }

        // Legacy rows booked before an account was required (Sprint 4).
        if ($byAccount->has(0)) {
            [$income, $expense] = self::sums($byAccount->get(0));
            $lines[] = $withBalances
                ? ['Tanpa Rekening', '-', '-', '-', $income, $expense, '-']
                : ['Tanpa Rekening', '-', '-', $income, $expense, round($income - $expense, 2)];
        }

        if (count($lines) > 1) {
            [$income, $expense] = self::sums($this->rows->reject(
                fn (FinanceTransaction $row) => $row->kategori === FinanceCategory::PindahDana,
            ));

            $lines[] = $withBalances
                ? ['Keseluruhan', '', '', round($openingTotal, 2), $income, $expense, round($closingTotal, 2)]
                : ['Keseluruhan', '', '', $income, $expense, round($income - $expense, 2)];
            $lines[] = [];
            $lines[] = ['Baris Keseluruhan tidak menghitung Pindah Dana antar rekening (uang tetap di perusahaan).'];
        }

        return $lines;
    }

    public function columnFormats(): array
    {
        return $this->showsBalances()
            ? ['D' => self::MONEY, 'E' => self::MONEY, 'F' => self::MONEY, 'G' => self::MONEY]
            : ['D' => self::MONEY, 'E' => self::MONEY, 'F' => self::MONEY];
    }

    public function title(): string
    {
        return 'Per Rekening';
    }
}
