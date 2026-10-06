<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 14 Sub 02 — "Buat RAB → Lainnya": a RAB Proyek with its own
     * name (e.g. "RAB Renovasi Pagar"). Same flow as any RAB Proyek; the
     * name is only what everyone (and the client) sees. Null = the type's
     * own label.
     */
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->string('custom_name', 100)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn('custom_name');
        });
    }
};
