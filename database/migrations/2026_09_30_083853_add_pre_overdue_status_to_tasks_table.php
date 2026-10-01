<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 9 decision #2: the status a task had right before
     * TaskService::markOverdueTasks() flipped it to OVER, so that a PM
     * moving the deadline to today or later (TaskService::update())
     * restores it instead of dumping the task back to PENDING. Null
     * whenever the task is not OVER.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('pre_overdue_status')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('pre_overdue_status');
        });
    }
};
