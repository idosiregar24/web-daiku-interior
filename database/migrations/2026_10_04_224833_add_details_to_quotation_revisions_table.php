<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 12 Sub 3 — a closed version's snapshot also keeps what the
     * Excel-format RAB adds around the items: items total, discount,
     * rounding and the payment scheme. Older snapshots have none (null).
     */
    public function up(): void
    {
        Schema::table('quotation_revisions', function (Blueprint $table) {
            $table->json('details')->nullable()->after('items');
        });
    }

    public function down(): void
    {
        Schema::table('quotation_revisions', function (Blueprint $table) {
            $table->dropColumn('details');
        });
    }
};
