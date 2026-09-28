<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.7 "riwayat pembayaran" hutang supplier — daiku_schema.sql
     * `supplier_debt_payments` (FK renamed `debt_id` → `supplier_debt_id`
     * per database-standards.md §2). Append-only: `created_at` only.
     * `bank_account_id` is required — every payment is also a cash-out
     * FinanceTransaction, and PRD §4.7 demands a bank account on those.
     */
    public function up(): void
    {
        Schema::create('supplier_debt_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_debt_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('paid_date');
            $table->foreignId('bank_account_id')->constrained();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('created_at')->nullable();

            $table->index(['supplier_debt_id', 'paid_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_debt_payments');
    }
};
