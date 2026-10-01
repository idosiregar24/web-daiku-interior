<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §4.7 "Gaji Karyawan Tetap" — one monthly salary payment. Not in
 * daiku_schema.sql (Sprint 9 design). `base_salary` is a snapshot of the
 * employee's salary at payment time; `amount` = base + allowance −
 * deduction, computed by PayrollService (never trusted from the client).
 *
 * UNIQUE(employee_id, period): one salary per employee per month — the
 * database backstop of PayrollService's check under a row lock.
 *
 * The matching GAJI_KARYAWAN FinanceTransaction is linked from here
 * (`finance_transaction_id`), NOT through `finance_transactions.reference_id`:
 * the Upah Tukang flow reads "GAJI_KARYAWAN + reference_id = task id" as
 * "this task's wage is paid" (StaffPaymentService::isTaskPaid()).
 *
 * Append-only (PRD §9.4): `created_at` only, restrictOnDelete FKs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->char('period', 7); // YYYY-MM
            $table->decimal('base_salary', 15, 2);
            $table->decimal('allowance', 15, 2)->default(0);
            $table->decimal('deduction', 15, 2)->default(0);
            $table->decimal('amount', 15, 2);
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $table->date('paid_at');
            $table->string('note', 255)->nullable();
            $table->foreignId('finance_transaction_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['employee_id', 'period']);
            $table->index('period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_payments');
    }
};
