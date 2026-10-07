<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 18 Sub 06 — when an unopened "klien menunggu" (P1) was pushed
     * to the devices a second time. Set once, before the push, so the
     * 15-minute job never rings anyone twice for the same row.
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->timestamp('repushed_at')->nullable()->after('read_at');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('repushed_at');
        });
    }
};
