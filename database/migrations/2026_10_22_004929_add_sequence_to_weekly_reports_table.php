<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 31 — a pengecekan may be sent more than once a week (File C:
 * 20 and 25 Oct fall in the same week): `sequence` numbers the pengecekan
 * of one week ("Minggu ke-3 · Pengecekan ke-2"), so (project, week) is no
 * longer unique. Projects without wages per item keep one report a week
 * (WeeklyReportService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weekly_reports', function (Blueprint $table) {
            $table->unsignedSmallInteger('sequence')->default(1)->after('week_number');
            // The plain index first: MySQL keeps the project_id foreign key on it.
            $table->index(['project_id', 'week_start']);
        });

        Schema::table('weekly_reports', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'week_start']);
        });
    }

    /** Fails while a week holds more than one pengecekan — those rows are the history the unique key forbids. */
    public function down(): void
    {
        Schema::table('weekly_reports', function (Blueprint $table) {
            $table->unique(['project_id', 'week_start']);
        });

        Schema::table('weekly_reports', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'week_start']);
            $table->dropColumn('sequence');
        });
    }
};
