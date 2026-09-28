<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * PRD §6.6: PM approval moves a request to PENDING_FINANCE (awaiting
     * Finance). The first build stored that resting state as APPROVED_PM
     * instead — same meaning, different value. Rename existing rows so
     * the stored value matches PRD §6.6 / daiku_schema.sql.
     */
    public function up(): void
    {
        DB::table('overtime_requests')
            ->where('status', 'APPROVED_PM')
            ->update(['status' => 'PENDING_FINANCE']);
    }

    public function down(): void
    {
        DB::table('overtime_requests')
            ->where('status', 'PENDING_FINANCE')
            ->update(['status' => 'APPROVED_PM']);
    }
};
