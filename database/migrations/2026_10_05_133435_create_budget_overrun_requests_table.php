<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 12 decision #28 — a realisation that would push a budget
     * post over its budget is held here (`payload` = the realisation as
     * typed) until the CEO approves (then it is recorded) or rejects it.
     * One MENUNGGU request per post at a time (BudgetRealizationService).
     * Finance data — never deleted.
     */
    public function up(): void
    {
        Schema::create('budget_overrun_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_post_id')->constrained()->restrictOnDelete();
            $table->foreignId('budget_line_id')->constrained()->restrictOnDelete();
            $table->json('payload');
            $table->decimal('amount_over', 15, 2);
            $table->text('reason');
            $table->foreignId('requested_by')->constrained('users');
            $table->string('status', 20)->default('MENUNGGU'); // MENUNGGU | DISETUJUI | DITOLAK
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['budget_post_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_overrun_requests');
    }
};
