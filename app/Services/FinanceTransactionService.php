<?php

namespace App\Services;

use App\Enums\FinanceTransactionType;
use App\Models\FinanceTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PRD §4.7 "Finance – Transaction" row (Finance CRUD, CEO/PM read) — the
 * single writer of FinanceTransaction rows for the Finance module's own
 * flows (StaffLoanService, SupplierDebtService, StaffPaymentService all
 * go through create()), so every row gets the same audit trail.
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
     * Income vs expense per month for the last `$months` months (current
     * month included), zero-filled — Finance's cash flow dashboard and the
     * CEO's Cash Flow Summary widget. Grouped in PHP, not with SQL
     * DATE_FORMAT(): that function breaks the SQLite test connection
     * (database-standards.md §1, see .claude/plan/README.md Sprint 4).
     *
     * @return Collection<int, array{month: string, label: string, income: float, expense: float}>
     */
    public function monthlyCashFlow(int $months = 6): Collection
    {
        $from = now()->subMonths($months - 1)->startOfMonth();

        $rows = FinanceTransaction::query()
            ->select('date', 'type', 'amount')
            ->where('date', '>=', $from->toDateString())
            ->get()
            ->groupBy(fn (FinanceTransaction $row) => $row->date->format('Y-m'));

        return collect(range(0, $months - 1))
            ->map(fn (int $i) => now()->startOfMonth()->subMonths($months - 1 - $i)->format('Y-m'))
            ->map(function (string $month) use ($rows) {
                $monthRows = $rows->get($month, collect());

                return [
                    'month' => $month,
                    'label' => Carbon::createFromFormat('Y-m', $month)->translatedFormat('M Y'),
                    'income' => (float) $monthRows->where('type', FinanceTransactionType::Income)->sum('amount'),
                    'expense' => (float) $monthRows->where('type', FinanceTransactionType::Expense)->sum('amount'),
                ];
            })
            ->values();
    }
}
