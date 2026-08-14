<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.5/§6.6 (Alur Pengajuan Lembur) + §5.1. Sequential approval:
     * PM then Finance — separate actor/timestamp columns per stage rather
     * than a single generic "approved_by", so both approvals are kept.
     */
    public function up(): void
    {
        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('hours', 5, 2);
            $table->decimal('rate_per_hour', 12, 2);
            $table->decimal('total_amount', 12, 2);
            $table->date('work_date');
            $table->text('reason');
            $table->string('status')->default('PENDING'); // PENDING/APPROVED_PM/APPROVED_FINANCE/REJECTED
            $table->foreignId('pm_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('pm_approved_at')->nullable();
            $table->foreignId('finance_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finance_approved_at')->nullable();
            $table->timestamps();

            $table->index(['staff_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_requests');
    }
};
