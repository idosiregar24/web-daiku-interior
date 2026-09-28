<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * daiku_schema.sql `staff_loan_payments` — FK renamed `loan_id` →
 * `staff_loan_id` (database-standards.md §2), plus `created_by` and a
 * nullable `task_id` for installments deducted from a wage payment.
 * Append-only: `created_at` only, no `updated_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_loan_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_loan_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('paid_date');
            $table->string('note')->nullable();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('created_at')->nullable();

            $table->index(['staff_loan_id', 'paid_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_loan_payments');
    }
};
