<?php

namespace App\Services;

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Models\BankAccount;
use App\Models\Employee;
use App\Models\SalaryPayment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PRD §4.7 "Gaji Karyawan Tetap: pencatatan dan pembayaran gaji bulanan
 * karyawan" — Sprint 9 decision #7. One salary per employee per month:
 * a GAJI_KARYAWAN expense on the chosen bank account plus the append-only
 * `salary_payments` row, together or not at all, audited as
 * `finance.salary_paid` (PRD §9.4).
 *
 * The transaction's `reference_id` is deliberately left NULL — the salary
 * row links to it through `finance_transaction_id` instead. The Upah
 * Tukang flow treats "kategori = GAJI_KARYAWAN AND reference_id = task id"
 * as "this task's wage is paid" (StaffPaymentService::isTaskPaid(),
 * FinanceTransactionController::staffPayments()), so a salary payment id
 * stored there would silently mark an unrelated DONE task as paid.
 */
class PayrollService
{
    public function __construct(
        private FinanceTransactionService $financeTransactionService,
        private AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array{period: string, paid_at: string, bank_account_id: int|string, allowance?: numeric-string|float|int|null, deduction?: numeric-string|float|int|null, note?: string|null}  $data
     */
    public function pay(Employee $employee, array $data, User $actor): SalaryPayment
    {
        return DB::transaction(function () use ($employee, $data, $actor) {
            // Locking the employee row serializes two "Bayar" clicks for the
            // same person, so the "already paid this month" check below
            // sees a payment the other request just committed.
            $locked = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $period = (string) $data['period'];
            $periodLabel = self::periodLabel($period);

            if (! $locked->is_active) {
                throw ValidationException::withMessages([
                    'employee_id' => "{$locked->name} sudah nonaktif — gajinya tidak bisa dibayarkan.",
                ]);
            }

            if ($period > now()->format('Y-m')) {
                throw ValidationException::withMessages([
                    'period' => 'Gaji bulan yang belum berjalan belum bisa dibayarkan.',
                ]);
            }

            if ($locked->join_date && $period < $locked->join_date->format('Y-m')) {
                throw ValidationException::withMessages([
                    'period' => "{$locked->name} baru bergabung {$locked->join_date->translatedFormat('d F Y')} — periode {$periodLabel} tidak bisa dibayarkan.",
                ]);
            }

            if (SalaryPayment::query()->where('employee_id', $locked->id)->forPeriod($period)->exists()) {
                throw $this->alreadyPaid($locked, $periodLabel);
            }

            // PRD §4.7 "Setiap transaksi wajib mencantumkan rekening bank".
            $bankAccountId = (int) ($data['bank_account_id'] ?? 0);

            if (! BankAccount::whereKey($bankAccountId)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([
                    'bank_account_id' => 'Rekening sumber wajib dipilih dan harus aktif.',
                ]);
            }

            $baseCents = self::cents($locked->base_salary);
            $allowanceCents = self::cents($data['allowance'] ?? 0);
            $deductionCents = self::cents($data['deduction'] ?? 0);

            if ($allowanceCents < 0 || $deductionCents < 0) {
                throw ValidationException::withMessages([
                    $allowanceCents < 0 ? 'allowance' : 'deduction' => 'Nominal tidak boleh negatif.',
                ]);
            }

            // Net pay is computed here, never taken from the client.
            $amountCents = $baseCents + $allowanceCents - $deductionCents;

            if ($amountCents < 0) {
                throw ValidationException::withMessages([
                    'deduction' => 'Potongan tidak boleh melebihi gaji pokok + tunjangan ('.self::rupiah($baseCents + $allowanceCents).').',
                ]);
            }

            $paidAt = $data['paid_at'];

            $transaction = $this->financeTransactionService->create([
                'bank_account_id' => $bankAccountId,
                'type' => FinanceTransactionType::Expense->value,
                'kategori' => FinanceCategory::GajiKaryawan->value,
                'amount' => self::decimal($amountCents),
                'description' => "Gaji {$locked->name} {$periodLabel}",
                // reference_id intentionally omitted (NULL) — see the class docblock.
                'date' => $paidAt,
            ], $actor);

            try {
                $payment = $locked->salaryPayments()->create([
                    'period' => $period,
                    'base_salary' => self::decimal($baseCents),
                    'allowance' => self::decimal($allowanceCents),
                    'deduction' => self::decimal($deductionCents),
                    'amount' => self::decimal($amountCents),
                    'bank_account_id' => $bankAccountId,
                    'paid_at' => $paidAt,
                    'note' => $data['note'] ?? null,
                    'finance_transaction_id' => $transaction->id,
                    'created_by' => $actor->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                // UNIQUE(employee_id, period) backstop — rolls the transaction back too.
                throw $this->alreadyPaid($locked, $periodLabel);
            }

            $this->auditLogService->record(
                'finance.salary_paid',
                $payment,
                null,
                [
                    'employee_id' => $locked->id,
                    'employee_name' => $locked->name,
                    'period' => $period,
                    'base_salary' => $payment->base_salary,
                    'allowance' => $payment->allowance,
                    'deduction' => $payment->deduction,
                    'amount' => $payment->amount,
                    'bank_account_id' => $bankAccountId,
                    'paid_at' => $paidAt,
                    'finance_transaction_id' => $transaction->id,
                ],
                $actor,
            );

            return $payment;
        });
    }

    /** "2026-09" → "September 2026" (app locale). */
    public static function periodLabel(string $period): string
    {
        // `!` pins the day to 1 — without it the current day is used and
        // e.g. "2026-02" parsed on the 30th rolls over into March.
        return Carbon::createFromFormat('!Y-m', $period)->translatedFormat('F Y');
    }

    private function alreadyPaid(Employee $employee, string $periodLabel): ValidationException
    {
        return ValidationException::withMessages([
            'period' => "Gaji {$employee->name} untuk {$periodLabel} sudah dibayar.",
        ]);
    }

    private static function cents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }

    private static function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private static function rupiah(int $cents): string
    {
        return 'Rp '.number_format($cents / 100, 0, ',', '.');
    }
}
