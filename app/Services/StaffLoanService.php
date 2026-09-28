<?php

namespace App\Services;

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Models\StaffLoan;
use App\Models\StaffLoanPayment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PRD §4.7 "Pinjaman Tukang". Finance lends cash to a field staff member
 * (a PINJAMAN expense from a bank account); the staff pays it back either
 * in cash (recordPayment) or through installments deducted from their
 * per-task wage (deductFromWage). Every repayment is a PINJAMAN income on
 * a bank account — for a wage deduction that income offsets part of the
 * gross GAJI_KARYAWAN expense StaffPaymentService records, so the cash
 * flow nets to what actually left the account. Every write is audited
 * (PRD §9.4).
 */
class StaffLoanService
{
    public function __construct(
        private FinanceTransactionService $financeTransactionService,
        private AuditLogService $auditLogService,
    ) {}

    public function create(array $data, User $actor): StaffLoan
    {
        return DB::transaction(function () use ($data, $actor) {
            $loan = StaffLoan::create([
                'staff_id' => $data['staff_id'],
                'amount' => $data['amount'],
                'paid_amount' => 0,
                'installment_amount' => $data['installment_amount'],
                'description' => $data['description'] ?? null,
                'bank_account_id' => $data['bank_account_id'],
                'created_by' => $actor->id,
            ])->refresh()->load('staff:id,name');

            // Cash leaves the company account — PRD §4.7 "Setiap transaksi
            // wajib mencantumkan rekening bank".
            $this->financeTransactionService->create([
                'bank_account_id' => $loan->bank_account_id,
                'type' => FinanceTransactionType::Expense->value,
                'kategori' => FinanceCategory::Pinjaman->value,
                'amount' => $loan->amount,
                'description' => "Pinjaman tukang — {$loan->staff->name}"
                    .($loan->description ? ": {$loan->description}" : ''),
                'reference_id' => $loan->id,
                'date' => now()->toDateString(),
            ], $actor);

            $this->auditLogService->record(
                'finance.staff_loan_created',
                $loan,
                null,
                $loan->only(['staff_id', 'amount', 'installment_amount', 'bank_account_id', 'description']),
                $actor,
            );

            return $loan;
        });
    }

    /**
     * Records one repayment. `$task` is set when the installment was
     * deducted from that task's wage. The loan row is locked so two
     * concurrent payments can't both pass the "≤ remaining" check.
     */
    public function recordPayment(StaffLoan $loan, array $data, User $actor, ?Task $task = null): StaffLoanPayment
    {
        return DB::transaction(function () use ($loan, $data, $actor, $task) {
            $locked = StaffLoan::query()->lockForUpdate()->findOrFail($loan->id);
            $amount = round((float) $data['amount'], 2);
            $remaining = (float) $locked->remaining;

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Nominal pembayaran harus lebih dari 0.',
                ]);
            }

            if ($amount > $remaining) {
                throw ValidationException::withMessages([
                    'amount' => 'Nominal pembayaran melebihi sisa pinjaman (Rp '
                        .number_format($remaining, 0, ',', '.').').',
                ]);
            }

            $oldPaid = $locked->paid_amount;

            $payment = $locked->payments()->create([
                'amount' => $amount,
                'paid_date' => $data['paid_date'] ?? now()->toDateString(),
                'note' => $data['note'] ?? null,
                'task_id' => $task?->id,
                'created_by' => $actor->id,
            ]);

            $locked->increment('paid_amount', $amount);
            $locked->refresh()->loadMissing('staff:id,name');

            $this->financeTransactionService->create([
                'bank_account_id' => $data['bank_account_id'],
                'type' => FinanceTransactionType::Income->value,
                'kategori' => FinanceCategory::Pinjaman->value,
                'amount' => $amount,
                'description' => ($task ? 'Potongan upah' : 'Pembayaran pinjaman')." — {$locked->staff->name}",
                'reference_id' => $locked->id,
                'date' => $payment->paid_date->toDateString(),
            ], $actor);

            $this->auditLogService->record(
                'finance.staff_loan_payment',
                $locked,
                ['paid_amount' => $oldPaid],
                [
                    'paid_amount' => $locked->paid_amount,
                    'remaining' => $locked->remaining,
                    'payment_id' => $payment->id,
                    'payment_amount' => $amount,
                    'task_id' => $task?->id,
                ],
                $actor,
            );

            $loan->setRawAttributes($locked->getAttributes(), true);

            return $payment;
        });
    }

    /**
     * Deducts loan installments from a wage about to be paid for `$task`:
     * outstanding loans oldest first, each capped at
     * min(installment_amount, remaining, wage left). Returns the total
     * deducted (the caller pays `$wage - total`); each deduction is booked
     * as PINJAMAN income on `$bankAccountId`, the account the wage is paid
     * from. Nested DB::transaction calls become savepoints, so this is
     * safe inside an outer transaction.
     */
    public function deductFromWage(User $staff, float $wage, Task $task, int $bankAccountId, User $actor): float
    {
        return DB::transaction(function () use ($staff, $wage, $task, $bankAccountId, $actor) {
            $total = 0.0;

            foreach ($this->planDeductions($staff, $wage, lock: true) as [$loan, $deduction]) {
                $this->recordPayment($loan, [
                    'amount' => $deduction,
                    'paid_date' => now()->toDateString(),
                    'bank_account_id' => $bankAccountId,
                    'note' => "Potongan upah task \"{$task->title}\"",
                ], $actor, $task);

                $total = round($total + $deduction, 2);
            }

            return $total;
        });
    }

    /**
     * What deductFromWage() would deduct — read-only, for the "Upah Tukang"
     * page. `$reserved` (loan id => amount) carries deductions already
     * previewed for earlier wages on the same page, so a staff member with
     * several unpaid tasks isn't shown the same installment on every row.
     *
     * @param  array<int, float>  $reserved
     */
    public function previewDeduction(User $staff, float $wage, array &$reserved = []): float
    {
        $total = 0.0;

        foreach ($this->planDeductions($staff, $wage, reserved: $reserved) as [$loan, $deduction]) {
            $reserved[$loan->id] = round(($reserved[$loan->id] ?? 0) + $deduction, 2);
            $total = round($total + $deduction, 2);
        }

        return $total;
    }

    /**
     * Outstanding loans oldest first, each capped at
     * min(installment_amount, remaining, wage left).
     *
     * @return list<array{0: StaffLoan, 1: float}>
     */
    private function planDeductions(User $staff, float $wage, bool $lock = false, array $reserved = []): array
    {
        $wageLeft = round($wage, 2);
        $plan = [];

        $loans = StaffLoan::query()
            ->where('staff_id', $staff->id)
            ->outstanding()
            ->orderBy('created_at')
            ->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get();

        foreach ($loans as $loan) {
            if ($wageLeft <= 0) {
                break;
            }

            $remaining = (float) $loan->remaining - ($reserved[$loan->id] ?? 0);
            $deduction = round(min((float) $loan->installment_amount, $remaining, $wageLeft), 2);

            if ($deduction > 0) {
                $plan[] = [$loan, $deduction];
                $wageLeft = round($wageLeft - $deduction, 2);
            }
        }

        return $plan;
    }
}
