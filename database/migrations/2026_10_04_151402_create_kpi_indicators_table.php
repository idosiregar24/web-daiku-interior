<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SDM (Sprint 10, §3.3): a KPI template's indicators. AUTO indicators are
 * computed from operational data by `metric_key` (KpiService), MANUAL ones
 * are filled in by HR. Scores are snapshotted into kpi_scores, so editing
 * an indicator never changes a closed month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_indicators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kpi_template_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('source', 10); // App\Enums\KpiIndicatorSource
            $table->string('metric_key', 60)->nullable();
            $table->decimal('target', 12, 2);
            $table->decimal('weight', 5, 2); // percent
            $table->string('direction', 20)->default('HIGHER_BETTER'); // App\Enums\KpiDirection
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_indicators');
    }
};
