<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 31 Sub 03 — "Jadwal per item" (File C, the light-blue cells): each
 * RAB item's production window and, optionally, its installation window
 * (the second block at the end of the month). Set by the Pengawas / Admin
 * Proyek (WorkItemService::updateSchedule()); all nullable — not scheduled yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_work_items', function (Blueprint $table) {
            $table->date('production_start')->nullable()->after('unit_wage');
            $table->date('production_end')->nullable()->after('production_start');
            $table->date('installation_start')->nullable()->after('production_end');
            $table->date('installation_end')->nullable()->after('installation_start');
        });
    }

    public function down(): void
    {
        Schema::table('project_work_items', function (Blueprint $table) {
            $table->dropColumn(['production_start', 'production_end', 'installation_start', 'installation_end']);
        });
    }
};
