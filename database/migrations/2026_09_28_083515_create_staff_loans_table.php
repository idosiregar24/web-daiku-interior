<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §4.7 "Pinjaman Tukang" — daiku_schema.sql `staff_loans`, with the
 * Sprint 8 deviations: bigint PKs (not ULIDs), `installment_amount`
 * (per-wage deduction) and `created_by`. Append-only finance record
 * (PRD §9.4): no soft deletes, no destroy route.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('remaining', 12, 2)->storedAs('amount - paid_amount');
            $table->decimal('installment_amount', 12, 2);
            $table->text('description')->nullable();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['staff_id', 'remaining']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_loans');
    }
};
