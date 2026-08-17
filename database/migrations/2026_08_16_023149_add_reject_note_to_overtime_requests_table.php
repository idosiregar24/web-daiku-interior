<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * daiku_schema.sql's `overtime_requests` has `reject_note` — missing
     * from the migration shipped in Sprint 1; added now because
     * OvertimeService's PM/Finance reject actions (Sprint 3 Week 6) are
     * the feature that actually needs it, same pattern as
     * tasks.kendala/note in Sprint 2 Week 4.
     */
    public function up(): void
    {
        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->text('reject_note')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->dropColumn('reject_note');
        });
    }
};
