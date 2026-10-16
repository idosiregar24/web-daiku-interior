<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 24 D6–D9 — a task is a "Daily Target": `planned_date` keeps the
     * day it was first planned for (due_date moves when it rolls over or is
     * moved), `rollover_count` how often it rolled to the next working day,
     * and `task_schedule_changes` the append-only history of every move
     * (ROLLOVER by the system, GESER / TUKAR by the Pengawas or PM).
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->date('planned_date')->nullable()->after('due_date');
            $table->unsignedSmallInteger('rollover_count')->default(0)->after('planned_date');
        });

        DB::table('tasks')->whereNull('planned_date')->update(['planned_date' => DB::raw('due_date')]);

        Schema::create('task_schedule_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->date('from_date')->nullable();
            $table->date('to_date');
            $table->string('kind', 20);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_schedule_changes');

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['planned_date', 'rollover_count']);
        });
    }
};
