<?php

namespace App\Services;

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Models\BankAccount;
use App\Models\FinanceTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PRD §4.7 "Finance – Transaction" row (Finance CRUD, CEO/PM read) — the
 * single writer of FinanceTransaction rows for the Finance module's own
 * flows (StaffLoanService, SupplierDebtService, StaffPaymentService,
 * FundTransferService all go through create()), so every row gets the
 * same audit trail. Also the one place that decides which totals are
 * company-level (Pindah Dana left out) and which are per account (Pindah
 * Dana included) — Sprint 9 decision #5.
 */
class FinanceTransactionService
{
    public function __construct(private AuditLogService $auditLogService) {}

    /**
     * @param  string  $auditAction  Callers recording a specific business event (e.g. StaffPaymentService's
     *                               `finance.staff_paid`) pass their own action so the Audit Log page labels it.
     */
    public function create(array $data, User $actor, string $auditAction = 'finance.transaction_created'): FinanceTransaction
    {
        return DB::transaction(fn () => $this->audited($auditAction, FinanceTransaction::create([
            'project_id' => $data['project_id'] ?? null,
            'bank_account_id' => $data['bank_account_id'],
            'type' => $data['type'],
            'kategori' => $data['kategori'],
            'amount' => $data['amount'],
            'description' => $data['description'],
            // Optional link back to the record that caused this cash movement
            // (a staff loan, a supplier debt payment, ...) — interpret it
            // together with `kategori`.
            'reference_id' => $data['reference_id'] ?? null,
            'date' => $data['date'],
            'created_by' => $actor->id,
        ]), $actor));
    }

    /** PRD §9.4 "perubahan finance" — every transaction this service writes is audited with it. */
    private function audited(string $action, FinanceTransaction $transaction, User $actor): FinanceTransaction
    {
        $this->auditLogService->record(
            $action,
            $transaction,
            null,
            $transaction->only(['project_id', 'bank_account_id', 'type', 'kategori', 'amount', 'description', 'reference_id', 'date']),
            $actor,
        );

        return $transaction;
    }

    /**
     * Whether totals over these filters are company-level, i.e. must leave
     * Pindah Dana out (moving money between the company's own accounts is
     * neither income nor expense, and its two legs would count the same
     * money twice). Scoped to one account — or explicitly listing Pindah
     * Dana — the legs are real money in/out of what's shown, so they stay.
     *
     * @param  array{bank_account_id?: int|string|null, kategori?: ?string}  $filters
     */
    public static function isCompanyLevel(array $filters): bool
    {
        return empty($filters['bank_account_id'])
            && ($filters['kategori'] ?? null) !== FinanceCategory::PindahDana->value;
    }

    /**
     * Transactions page summary over the same filters as its list
     * (FinanceTransaction::scopeFilter()).
     *
     * @return array{income: float, expense: float, net: float, excludesTransfers: bool}
     */
    public function summarize(array $filters): array
    {
        $companyLevel = self::isCompanyLevel($filters);
        $base = FinanceTransaction::query()
            ->filter($filters)
            ->when($companyLevel, fn (Builder $q) => $q->excludingInternalTransfers());

        $income = (float) (clone $base)->where('type', FinanceTransactionType::Income->value)->sum('amount');
        $expense = (float) (clone $base)->where('type', FinanceTransactionType::Expense->value)->sum('amount');

        return [
            'income' => round($income, 2),
            'expense' => round($expense, 2),
            'net' => round($income - $expense, 2),
            'excludesTransfers' => $companyLevel,
        ];
    }

    /**
     * Company income vs expense per month for the last `$months` months
     * (current month included), zero-filled — Finance's cash flow dashboard
     * and the CEO's Cash Flow Summary widget. Pindah Dana is left out
     * (company level). Grouped in PHP, not with SQL DATE_FORMAT(): that
     * function breaks the SQLite test connection (database-standards.md
     * §1, see .claude/plan/README.md Sprint 4).
     *
     * @return Collection<int, array{month: string, label: string, income: float, expense: float}>
     */
    public function monthlyCashFlow(int $months = 6): Collection
    {
        $from = now()->subMonths($months - 1)->startOfMonth();

        $rows = FinanceTransaction::query()
            ->select('date', 'type', 'amount')
            ->where('date', '>=', $from->toDateString())
            ->excludingInternalTransfers()
            ->get()
            ->groupBy(fn (FinanceTransaction $row) => $row->date->format('Y-m'));

        return collect(range(0, $months - 1))
            ->map(fn (int $i) => now()->startOfMonth()->subMonths($months - 1 - $i)->format('Y-m'))
            ->map(function (string $month) use ($rows) {
                $monthRows = $rows->get($month, collect());

                return [
                    'month' => $month,
                    // `!` = day 1 — without it the 29th–31st overflow into the next month.
                    'label' => Carbon::createFromFormat('!Y-m', $month)->translatedFormat('M Y'),
                    'income' => (float) $monthRows->where('type', FinanceTransactionType::Income)->sum('amount'),
                    'expense' => (float) $monthRows->where('type', FinanceTransactionType::Expense)->sum('amount'),
                ];
            })
            ->values();
    }

    /**
     * PRD §4.7 "Cash Flow Dashboard: Ringkasan pemasukan dan pengeluaran
     * per rekening dan keseluruhan". Per account: saldo awal, all-time
     * masuk/keluar, saldo saat ini, and masuk/keluar within `$month` —
     * Pindah Dana legs included (for one account they really are money
     * in/out). Active accounts, plus inactive ones still holding money or
     * moving within `$month`, so no rupiah silently drops out of the
     * total. The "Keseluruhan" row sums those accounts' balances, while
     * its masuk/keluar are company-level (Pindah Dana left out).
     *
     * @return array{month: string, label: string, accounts: list<array<string, mixed>>, total: array<string, float>}
     */
    public function accountSummary(Carbon $month): array
    {
        $monthStart = $month->copy()->startOfMonth()->toDateString();
        $monthEnd = $month->copy()->startOfMonth()->addMonthNoOverflow()->toDateString();
        // Plain comparisons, not whereDate(): SQLite (tests) stores `date`
        // with a time part, and "< first day of next month" covers both.
        $inMonth = fn (Builder $q) => $q->where('date', '>=', $monthStart)->where('date', '<', $monthEnd);

        $accounts = BankAccount::query()
            ->withBalance()
            ->withSum(['transactions as month_income' => fn (Builder $q) => $inMonth($q)->where('type', FinanceTransactionType::Income->value)], 'amount')
            ->withSum(['transactions as month_expense' => fn (Builder $q) => $inMonth($q)->where('type', FinanceTransactionType::Expense->value)], 'amount')
            ->orderByDesc('is_active')
            ->orderBy('label')
            ->get()
            ->map(fn (BankAccount $account) => [
                'id' => $account->id,
                'label' => $account->label,
                'bank_name' => $account->bank_name,
                'account_no' => $account->account_no,
                'is_active' => $account->is_active,
                'opening_balance' => (float) $account->opening_balance,
                'total_income' => (float) $account->total_income,
                'total_expense' => (float) $account->total_expense,
                'current_balance' => $account->current_balance,
                'month_income' => (float) $account->month_income,
                'month_expense' => (float) $account->month_expense,
            ])
            ->filter(fn (array $row) => $row['is_active']
                || abs($row['current_balance']) >= 0.01
                || $row['month_income'] > 0
                || $row['month_expense'] > 0)
            ->values();

        $companySum = fn (FinanceTransactionType $type, bool $monthOnly) => round((float) FinanceTransaction::query()
            ->whereIn('bank_account_id', $accounts->pluck('id'))
            ->excludingInternalTransfers()
            ->where('type', $type->value)
            ->when($monthOnly, $inMonth)
            ->sum('amount'), 2);

        return [
            'month' => $month->format('Y-m'),
            'label' => $month->translatedFormat('F Y'),
            'accounts' => $accounts->all(),
            'total' => [
                'opening_balance' => round($accounts->sum('opening_balance'), 2),
                'total_income' => $companySum(FinanceTransactionType::Income, false),
                'total_expense' => $companySum(FinanceTransactionType::Expense, false),
                'current_balance' => round($accounts->sum('current_balance'), 2),
                'month_income' => $companySum(FinanceTransactionType::Income, true),
                'month_expense' => $companySum(FinanceTransactionType::Expense, true),
            ],
        ];
    }

    /**
     * Each account's balance at the start of `$date` (saldo awal +
     * everything strictly before that day), keyed by account id — the
     * "Saldo Awal Periode" of the per-account export sheet. Null `$date`
     * means before any transaction: just the saldo awal.
     *
     * @param  iterable<int, BankAccount>  $accounts
     * @return array<int, float>
     */
    public function balancesAt(iterable $accounts, ?string $date): array
    {
        $net = $date === null ? collect() : FinanceTransaction::query()
            ->whereNotNull('bank_account_id')
            ->whereDate('date', '<', $date)
            ->selectRaw('bank_account_id, type, SUM(amount) as total')
            ->groupBy('bank_account_id', 'type')
            ->get()
            ->groupBy('bank_account_id')
            ->map(fn (Collection $rows) => $rows->sum(fn (FinanceTransaction $row) => $row->type === FinanceTransactionType::Income
                ? (float) $row->getAttribute('total')
                : -(float) $row->getAttribute('total')));

        $balances = [];

        foreach ($accounts as $account) {
            $balances[$account->id] = round((float) $account->opening_balance + (float) $net->get($account->id, 0), 2);
        }

        return $balances;
    }
}
