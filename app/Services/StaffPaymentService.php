<?php

namespace App\Services;

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Enums\TaskStatus;
use App\Models\FinanceTransaction;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Pencatatan upah tukang per task selesai" (CSV Sprint 4) + PRD §4.7
 * "cicilan [pinjaman tukang] dipotong otomatis dari upah saat
 * pembayaran". Lives outside FinanceTransactionService because it
 * orchestrates both it and StaffLoanService (which itself depends on
 * FinanceTransactionService — keeping this here avoids a constructor
 * cycle).
 *
 * Ledger shape for one wage payment of W with loan deduction D:
 *   - GAJI_KARYAWAN expense W (gross — the real labour cost of the task)
 *   - PINJAMAN income D per loan installment (StaffLoanService)
 * both on the same bank account, so the account's net movement is W − D,
 * which is what is actually transferred to the staff member.
 */
class StaffPaymentService
{
    public function __construct(
        private FinanceTransactionService $financeTransactionService,
        private StaffLoanService $staffLoanService,
    ) {}

    /**
     * "Already paid" is derived from whether a GAJI_KARYAWAN transaction
     * already references this task, rather than an `is_paid` column on
     * Task (Task stays immutable per CLAUDE.md golden rule #6).
     */
    public function isTaskPaid(Task $task): bool
    {
        return FinanceTransaction::query()
            ->where('kategori', FinanceCategory::GajiKaryawan->value)
            ->where('reference_id', $task->id)
            ->exists();
    }

    /**
     * Pass the same `$reserved` array for every task on a page so the
     * preview assumes the tasks are paid in that order (see
     * StaffLoanService::previewDeduction()).
     *
     * @param  array<int, float>  $reserved
     * @return array{wage: float, deduction: float, net: float}
     */
    public function preview(Task $task, array &$reserved = []): array
    {
        $wage = round((float) $task->rate_per_task, 2);
        $deduction = $task->assignee
            ? $this->staffLoanService->previewDeduction($task->assignee, $wage, $reserved)
            : 0.0;

        return ['wage' => $wage, 'deduction' => $deduction, 'net' => round($wage - $deduction, 2)];
    }

    /** Pays a single DONE task once. Returns the gross GAJI_KARYAWAN transaction. */
    public function pay(Task $task, int $bankAccountId, User $actor): FinanceTransaction
    {
        return DB::transaction(function () use ($task, $bankAccountId, $actor) {
            // Locking the task row serializes two concurrent "Bayar" clicks
            // (and a concurrent status change), so every check below runs
            // against the row as it will be paid.
            $task = Task::query()->with('assignee:id,name')->lockForUpdate()->findOrFail($task->id);

            if ($task->status !== TaskStatus::Done) {
                throw ValidationException::withMessages([
                    'status' => 'Task ini belum DONE — upah belum bisa dicatat.',
                ]);
            }

            if (! $task->rate_per_task) {
                throw ValidationException::withMessages([
                    'status' => 'Task ini tidak punya rate_per_task.',
                ]);
            }

            if ($this->isTaskPaid($task)) {
                throw ValidationException::withMessages([
                    'status' => 'Upah untuk task ini sudah pernah dicatat.',
                ]);
            }

            $wage = round((float) $task->rate_per_task, 2);

            $transaction = $this->financeTransactionService->create([
                'project_id' => $task->project_id,
                'bank_account_id' => $bankAccountId,
                'type' => FinanceTransactionType::Expense->value,
                'kategori' => FinanceCategory::GajiKaryawan->value,
                'amount' => $wage,
                'description' => "Upah task \"{$task->title}\" — {$task->assignee?->name}",
                'reference_id' => $task->id,
                'date' => now()->toDateString(),
            ], $actor, 'finance.staff_paid');

            if ($task->assignee) {
                $this->staffLoanService->deductFromWage($task->assignee, $wage, $task, $bankAccountId, $actor);
            }

            return $transaction;
        });
    }
}
