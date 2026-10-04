<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SDM (Sprint 10, decision #3): base-salary changes — HR requests, CEO
 * approves or rejects. On approval `employees.base_salary` is updated in
 * the same transaction (SalaryChangeService). A decided row is never
 * edited again; at most one PENDING row per employee (service-enforced).
 * `performance_review_id` is set when the request comes from a review's
 * "naik gaji" recommendation (§3.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->decimal('old_salary', 15, 2);
            $table->decimal('new_salary', 15, 2);
            $table->date('effective_date');
            $table->text('reason');
            $table->string('status', 20)->default('PENDING'); // App\Enums\SalaryChangeStatus
            $table->text('reject_note')->nullable();
            $table->unsignedBigInteger('performance_review_id')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_changes');
    }
};
