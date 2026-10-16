<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 24 D4/D10 — "Jasa Pengawas": the fee the PM sets at the TM
     * (total + DP %), the weekly Friday reports, and every payment request
     * (DP / MINGGUAN / PELUNASAN) through PM approval to Finance. Payments
     * and reports are never deleted (golden rule #7) — no destroy route.
     */
    public function up(): void
    {
        Schema::create('supervisor_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('total_amount', 15, 2);
            $table->decimal('dp_percent', 5, 2)->default(10);
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('weekly_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('week_start');
            $table->unsignedSmallInteger('week_number');
            $table->text('notes');
            $table->json('links')->nullable();
            $table->string('status', 20)->default('DIAJUKAN')->index();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'week_start']);
        });

        Schema::create('supervisor_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supervisor_fee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 20);
            $table->decimal('amount', 15, 2);
            $table->string('status', 20)->default('DIAJUKAN')->index();
            $table->foreignId('weekly_report_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('finance_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisor_payments');
        Schema::dropIfExists('weekly_reports');
        Schema::dropIfExists('supervisor_fees');
    }
};
