<?php

namespace App\Services;

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Models\Project;
use App\Models\SupplierDebt;
use App\Models\SupplierDebtPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PRD §4.7 "Hutang Supplier: tracking hutang ke supplier + riwayat
 * pembayaran". RBAC follows §7.1 "Finance – Transaction" (Finance
 * create/update, CEO/PM read — enforced by route middleware).
 *
 * Cash-flow rule (deliberate deviation from PRD §4.7's literal "Hutang
 * supplier muncul sebagai pengeluaran saat dibuat, dilunasi saat bayar"):
 * creating a debt records ONLY the liability — no FinanceTransaction.
 * Each PAYMENT writes one cash-out FinanceTransaction (PENGELUARAN /
 * HUTANG_IDEAL, the payment's bank account, reference_id = debt id).
 * Booking an expense at creation *and* at payment would count the same
 * cash twice; booking it only at creation would show cash leaving a bank
 * account before it actually did.
 *
 * Append-only (PRD §9.4): no update/delete — corrections are new payments.
 */
class SupplierDebtService
{
    public function __construct(
        private AuditLogService $auditLogService,
        private FinanceTransactionService $financeTransactionService,
    ) {}

    public function create(array $data, User $actor): SupplierDebt
    {
        return DB::transaction(function () use ($data, $actor) {
            $debt = SupplierDebt::create([
                'supplier_name' => $data['supplier_name'],
                'total_amount' => $data['total_amount'],
                'paid_amount' => 0,
                'project_id' => $data['project_id'] ?? null,
                'description' => $data['description'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->auditLogService->record(
                'finance.supplier_debt_created',
                $debt,
                null,
                $debt->only(['supplier_name', 'total_amount', 'project_id', 'description', 'due_date']),
                $actor,
            );

            // Pull the generated `remaining` column back from the DB.
            return $debt->fresh();
        });
    }

    /**
     * Records one installment: bumps `paid_amount`, appends the payment
     * row, and writes the matching cash-out FinanceTransaction — all or
     * nothing. The debt row is locked so two concurrent payments can't
     * both pass the "not more than remaining" check.
     */
    public function recordPayment(SupplierDebt $debt, array $data, User $actor): SupplierDebtPayment
    {
        return DB::transaction(function () use ($debt, $data, $actor) {
            $debt = SupplierDebt::query()->lockForUpdate()->findOrFail($debt->id);

            $amountCents = (int) round((float) $data['amount'] * 100);
            $totalCents = (int) round((float) $debt->total_amount * 100);
            $paidCents = (int) round((float) $debt->paid_amount * 100);
            $remainingCents = $totalCents - $paidCents;

            if ($amountCents <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Nominal pembayaran harus lebih dari 0.',
                ]);
            }

            if ($remainingCents <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Hutang ini sudah lunas.',
                ]);
            }

            if ($amountCents > $remainingCents) {
                throw ValidationException::withMessages([
                    'amount' => 'Nominal pembayaran melebihi sisa hutang (Rp '
                        .number_format($remainingCents / 100, 0, ',', '.').').',
                ]);
            }

            $oldPaid = $debt->paid_amount;
            $newPaid = number_format(($paidCents + $amountCents) / 100, 2, '.', '');
            $debt->update(['paid_amount' => $newPaid]);

            $payment = $debt->payments()->create([
                'amount' => number_format($amountCents / 100, 2, '.', ''),
                'paid_date' => $data['paid_date'],
                'bank_account_id' => $data['bank_account_id'],
                'note' => $data['note'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->auditLogService->record(
                'finance.supplier_debt_paid',
                $debt,
                ['paid_amount' => $oldPaid],
                [
                    'paid_amount' => $newPaid,
                    'payment_id' => $payment->id,
                    'amount' => $payment->amount,
                    'paid_date' => $payment->paid_date,
                    'bank_account_id' => $payment->bank_account_id,
                ],
                $actor,
            );

            $this->financeTransactionService->create([
                'project_id' => $debt->project_id,
                'bank_account_id' => $payment->bank_account_id,
                'type' => FinanceTransactionType::Expense->value,
                'kategori' => FinanceCategory::HutangIdeal->value,
                'amount' => $payment->amount,
                'description' => "Pembayaran hutang supplier {$debt->supplier_name}",
                'reference_id' => $debt->id,
                'date' => $data['paid_date'],
            ], $actor);

            return $payment;
        });
    }

    /** Derived display status — LUNAS / JATUH_TEMPO / BERJALAN (see SupplierDebt::status()). */
    public function displayStatus(SupplierDebt $debt): string
    {
        return $debt->status;
    }

    /**
     * Unpaid supplier debts tied to a project, most urgent first — for the
     * project Finance tab. Sum with `->sum('remaining')`.
     *
     * @return Collection<int, SupplierDebt>
     */
    public function outstandingForProject(Project $project): Collection
    {
        return SupplierDebt::query()
            ->where('project_id', $project->id)
            ->outstanding()
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->get();
    }

    public function outstandingTotalForProject(Project $project): float
    {
        return (float) SupplierDebt::query()
            ->where('project_id', $project->id)
            ->outstanding()
            ->sum('remaining');
    }
}
