<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 12 Sub 2 (decisions #1, #4) — the date Marketing first
     * contacted the client (separate from `created_at`, when the lead
     * entered the system) and the client's address: general text plus a
     * Google Maps link (http/https only, validated in the requests).
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->date('first_contacted_at')->nullable()->after('contact');
            $table->text('address')->nullable()->after('city');
            $table->string('maps_url', 500)->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['first_contacted_at', 'address', 'maps_url']);
        });
    }
};
