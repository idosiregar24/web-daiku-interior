<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 22 — the architect hands a finished design (or revision) to
     * Marketing: "siap dikirim" is a mark, not a status (K1), cleared once
     * Marketing sends it to the client. `ready_note` is the architect's
     * optional word to Marketing ("revisi warna bar sudah").
     */
    public function up(): void
    {
        Schema::table('designs', function (Blueprint $table) {
            $table->timestamp('ready_for_client_at')->nullable()->after('sent_to_client_at');
            $table->string('ready_note', 500)->nullable()->after('ready_for_client_at');
        });
    }

    public function down(): void
    {
        Schema::table('designs', function (Blueprint $table) {
            $table->dropColumn(['ready_for_client_at', 'ready_note']);
        });
    }
};
