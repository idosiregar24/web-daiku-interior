<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 29 Sub 09 (B6) — every Upah Tukang payment of a project with wages
 * per RAB item carries its split per Kepala Tim, frozen when it is asked
 * (scaled when the PM changes the amount): [{"label": "Putra", "amount": 4551500}].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supervisor_payments', function (Blueprint $table) {
            $table->json('breakdown')->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('supervisor_payments', function (Blueprint $table) {
            $table->dropColumn('breakdown');
        });
    }
};
