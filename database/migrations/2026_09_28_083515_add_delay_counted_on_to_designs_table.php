<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.2 "Sistem hitung delay_hari otomatis setiap hari" +
     * "HOLD_CLIENT dan REVISI_CLIENT tidak menghitung delay". `delay_hari`
     * is accumulated incrementally (DesignService::recalculateDelays()),
     * so it needs to remember the last day already counted — that keeps
     * the job idempotent and keeps suspended days out of the count.
     */
    public function up(): void
    {
        Schema::table('designs', function (Blueprint $table) {
            $table->date('delay_counted_on')->nullable()->after('delay_hari');
        });
    }

    public function down(): void
    {
        Schema::table('designs', function (Blueprint $table) {
            $table->dropColumn('delay_counted_on');
        });
    }
};
