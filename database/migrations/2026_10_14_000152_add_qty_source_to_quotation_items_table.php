<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 23 K5 — where a RAB line's volume came from: null = typed by
     * the Estimator, `P` / `TL` = one dimension (satuan m/lari), `PxTL` =
     * P × T/L (every other unit). The volume itself stays in `qty` and is
     * never rewritten by the server; this only marks it "otomatis" in the
     * table. Existing lines stay null (manual).
     */
    public function up(): void
    {
        Schema::table('quotation_items', function (Blueprint $table) {
            $table->string('qty_source', 10)->nullable()->after('qty');
        });
    }

    public function down(): void
    {
        Schema::table('quotation_items', function (Blueprint $table) {
            $table->dropColumn('qty_source');
        });
    }
};
