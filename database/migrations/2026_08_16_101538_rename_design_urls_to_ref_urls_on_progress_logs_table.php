<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Sprint 1 scaffolding migration named this column `design_urls`
     * — a copy/paste drift from `designs.design_urls`. daiku_schema.sql
     * (authoritative per CLAUDE.md golden rule #1) names it `ref_urls`
     * for `progress_logs`; fixed here now that ProgressLogService is
     * actually being built (Sprint 4). Drop+add instead of
     * `renameColumn()` — the table is still empty (no writers existed
     * before this sprint), and `renameColumn()` needs doctrine/dbal,
     * which isn't installed.
     */
    public function up(): void
    {
        Schema::table('progress_logs', function (Blueprint $table) {
            $table->dropColumn('design_urls');
        });

        Schema::table('progress_logs', function (Blueprint $table) {
            $table->json('ref_urls')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('progress_logs', function (Blueprint $table) {
            $table->dropColumn('ref_urls');
        });

        Schema::table('progress_logs', function (Blueprint $table) {
            $table->json('design_urls')->nullable()->after('description');
        });
    }
};
