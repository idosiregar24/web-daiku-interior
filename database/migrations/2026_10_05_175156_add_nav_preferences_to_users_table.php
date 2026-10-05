<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 13 Sub 01 / D3 — per-user sidebar preferences (collapsed
     * groups), stored on the account so they follow the user from laptop
     * to phone. Null = defaults (every group open).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('nav_preferences')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('nav_preferences');
        });
    }
};
