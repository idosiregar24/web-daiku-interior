<?php

namespace Database\Factories;

use App\Enums\FinanceCategory;
use App\Models\BankAccount;
use App\Models\Employee;
use App\Models\FinanceTransaction;
use App\Models\SalaryPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Raw ledger row only — the linked FinanceTransaction is a bare factory
 * row. Use PayrollService::pay() for a real payment.
 *
 * @extends Factory<SalaryPayment>
 */
class SalaryPaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'period' => now()->format('Y-m'),
            'base_salary' => 4_000_000,
            'allowance' => 0,
            'deduction' => 0,
            'amount' => 4_000_000,
            'bank_account_id' => BankAccount::factory(),
            'paid_at' => now()->toDateString(),
            'note' => null,
            'finance_transaction_id' => FinanceTransaction::factory()->state(['kategori' => FinanceCategory::GajiKaryawan->value]),
            'created_by' => User::factory(),
        ];
    }
}
