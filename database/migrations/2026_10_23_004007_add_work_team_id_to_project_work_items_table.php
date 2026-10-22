<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 37 — a Tim Tukang template picked for a RAB item: the item's Kepala
 * Tim is the template's ketua and its members are copied into the project's
 * Pembagian Upah (WorkItemService::update()). Kept to show which team an item
 * was given; a deleted template leaves the item's Kepala Tim as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_work_items', function (Blueprint $table) {
            $table->foreignId('work_team_id')->nullable()->after('team_lead_name')->constrained('work_teams')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_work_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_team_id');
        });
    }
};
