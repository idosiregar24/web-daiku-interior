<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SDM (Sprint 10, §3.3): one employee's score on one indicator in one
 * month. The indicator's name/source/target/weight/direction are copied
 * in (snapshot) so later template edits never change a closed month.
 * `kpi_indicator_id` is nulled if the indicator is later removed — the
 * snapshot still describes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kpi_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('kpi_indicator_id')->nullable()->constrained()->nullOnDelete();
            $table->string('indicator_name', 150);
            $table->string('source', 10);
            $table->string('metric_key', 60)->nullable();
            $table->decimal('target', 12, 2);
            $table->decimal('weight', 5, 2);
            $table->string('direction', 20);
            $table->decimal('actual', 12, 2)->nullable(); // null = not filled/computed yet
            $table->decimal('score', 6, 2)->nullable(); // 0–120
            $table->decimal('weighted_score', 6, 2)->nullable();
            $table->foreignId('input_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['kpi_period_id', 'employee_id', 'kpi_indicator_id']);
            $table->index(['employee_id', 'kpi_period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_scores');
    }
};
