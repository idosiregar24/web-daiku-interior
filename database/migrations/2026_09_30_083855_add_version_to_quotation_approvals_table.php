<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which quotation version each CEO/PM/client decision was about
     * (Sprint 9 — quotation revisions). Once a rejection bumps
     * `quotations.version`, the approval history spans several versions
     * and would be ambiguous without it. Every existing row is version 1:
     * nothing ever incremented `quotations.version` before this sprint,
     * so the default is also the correct backfill.
     */
    public function up(): void
    {
        Schema::table('quotation_approvals', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('quotation_id');
        });
    }

    public function down(): void
    {
        Schema::table('quotation_approvals', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
