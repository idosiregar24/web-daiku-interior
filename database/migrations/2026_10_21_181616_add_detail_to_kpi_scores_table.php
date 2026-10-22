<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 29 Sub 14 — the breakdown behind an AUTO actual (today only the
 * "Aktivitas Harian" metrics: working days, filled, HR's checks, the day
 * list). Written with the actual on every compute and frozen with it when
 * the month is CLOSED (KpiScore refuses updates then), so a closed month's
 * day list never drifts from its score. Null for metrics without one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_scores', function (Blueprint $table) {
            $table->json('detail')->nullable()->after('actual');
        });
    }

    public function down(): void
    {
        Schema::table('kpi_scores', function (Blueprint $table) {
            $table->dropColumn('detail');
        });
    }
};
