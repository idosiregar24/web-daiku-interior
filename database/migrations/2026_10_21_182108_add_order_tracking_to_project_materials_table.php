<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 29 Sub 11 (E3) — "Dipesan": Logistik records that a bought line
     * was ordered (who, when, from which vendor — `vendor_id` already exists —
     * and when it should arrive). The new DIRENCANAKAN request status and the
     * RENCANA channel are plain strings in existing columns.
     */
    public function up(): void
    {
        Schema::table('project_materials', function (Blueprint $table) {
            $table->timestamp('ordered_at')->nullable()->after('reviewed_at');
            $table->foreignId('ordered_by')->nullable()->after('ordered_at')->constrained('users')->nullOnDelete();
            $table->date('expected_at')->nullable()->after('ordered_by');
            $table->string('order_note', 500)->nullable()->after('expected_at');

            // The Logistik queue: a project's submitted plan lines.
            $table->index(['request_channel', 'request_status']);
        });
    }

    public function down(): void
    {
        Schema::table('project_materials', function (Blueprint $table) {
            $table->dropIndex(['request_channel', 'request_status']);
            $table->dropConstrainedForeignId('ordered_by');
            $table->dropColumn(['ordered_at', 'expected_at', 'order_note']);
        });
    }
};
