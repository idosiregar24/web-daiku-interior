<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.10 "Revenue vs Target: Grafik nilai kontrak vs target bulan
     * berjalan" — CSV Sprint 6: "input target manual per bulan". Not in
     * daiku_schema.sql (the PRD never says where targets live). One row
     * per calendar month, set by the CEO from the Analytics page.
     */
    public function up(): void
    {
        Schema::create('revenue_targets', function (Blueprint $table) {
            $table->id();
            $table->char('month', 7)->unique(); // 'YYYY-MM'
            $table->decimal('target_amount', 15, 2);
            $table->foreignId('set_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_targets');
    }
};
