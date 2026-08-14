<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.7 + §5.1. `bank_account_id` (PRD §4.7 "setiap transaksi
     * wajib mencantumkan rekening bank") is deliberately deferred to a
     * follow-up migration once a `bank_accounts` table is designed in
     * Sprint 4 — not part of PRD §5.1's schema sketch and out of scope
     * for this migration-only task.
     */
    public function up(): void
    {
        Schema::create('finance_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // INCOME/EXPENSE/TERMIN/STAFF_PAY/OVERTIME_PAY/PENALTY_COLLECT
            $table->decimal('amount', 15, 2);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable(); // termin_id / overtime_id / dll — polymorphic-by-type
            $table->date('date');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->json('attachments')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'date']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_transactions');
    }
};
