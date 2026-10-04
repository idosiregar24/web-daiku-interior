<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SDM (Sprint 10, §3.3): monthly KPI period. OPEN → (compute + manual
 * input) → CLOSED; closing locks every kpi_scores row of the month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_periods', function (Blueprint $table) {
            $table->id();
            $table->char('period', 7)->unique(); // YYYY-MM
            $table->string('status', 10)->default('OPEN'); // App\Enums\KpiPeriodStatus
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_periods');
    }
};
