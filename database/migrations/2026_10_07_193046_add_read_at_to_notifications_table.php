<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 18 Sub 02 — *when* a notification was opened, not just
     * whether. Base for re-pushing an unopened P1 (Sub 06) and for
     * measuring response speed. `is_read` stays: the bell's
     * (user_id, is_read) index keeps serving the unread count.
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable()->after('is_read');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('read_at');
        });
    }
};
