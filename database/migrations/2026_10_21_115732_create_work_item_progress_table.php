<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 29 Sub 09 (C1) — "Pengecekan % per item" (File B, CEK 1 … CEK n):
 * every check adds a row per item with the % added that time. Append-only —
 * a correction is a negative row with a note; Σ per item stays 0–100
 * (WorkItemService). An item's progress = Σ percent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_item_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_work_item_id')->constrained()->cascadeOnDelete();
            // The weekly report (pengecekan) it was sent with.
            $table->foreignId('weekly_report_id')->nullable()->constrained()->nullOnDelete();
            $table->date('checked_on');
            // Signed: a correction is negative (with a note).
            $table->decimal('percent', 5, 2);
            $table->string('note', 500)->nullable();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();

            $table->index(['project_id', 'project_work_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_progress');
    }
};
