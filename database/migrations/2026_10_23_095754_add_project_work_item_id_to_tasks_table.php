<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 38 — a daily target may work on one RAB item of its timeline
 * (`milestone_work_item`): the item's "Progres Target" = its targets done ÷
 * all its targets (WorkItemService::targetProgress()). Optional — a target
 * like "beli bahan" has none; a deleted item leaves the target without one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('project_work_item_id')->nullable()->after('milestone_id')->constrained('project_work_items')->nullOnDelete();
            $table->index(['project_work_item_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // The foreign key first — MySQL may serve it with the composite index.
            $table->dropForeign(['project_work_item_id']);
            $table->dropIndex(['project_work_item_id', 'status']);
            $table->dropColumn('project_work_item_id');
        });
    }
};
