<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SDM (Sprint 10, §3.4): semester performance review, written by HR
 * (decision #7), approved by the CEO, acknowledged by the employee.
 * DRAFT → SUBMITTED → APPROVED → ACKNOWLEDGED (CEO may return SUBMITTED to
 * DRAFT with `return_note`). Locked once APPROVED (PerformanceReviewService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('semester'); // 1 = Jan–Jun, 2 = Jul–Des
            $table->decimal('kpi_average', 6, 2)->nullable();
            $table->unsignedTinyInteger('kpi_months')->default(0); // closed months the average covers
            $table->json('discipline_summary')->nullable();
            $table->decimal('discipline_score', 6, 2)->nullable();
            $table->json('qualitative')->nullable(); // {attitude, teamwork, initiative, responsibility}: 1–5
            $table->decimal('qualitative_score', 6, 2)->nullable();
            $table->json('weights')->nullable(); // {kpi, qualitative, discipline} — percent, total 100
            $table->decimal('final_score', 6, 2)->nullable();
            $table->string('grade', 1)->nullable(); // App\Enums\ReviewGrade
            $table->string('recommendation', 20)->nullable(); // App\Enums\ReviewRecommendation
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('DRAFT'); // App\Enums\ReviewStatus
            $table->text('return_note')->nullable();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'year', 'semester']);
            $table->index(['status', 'year', 'semester']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_reviews');
    }
};
