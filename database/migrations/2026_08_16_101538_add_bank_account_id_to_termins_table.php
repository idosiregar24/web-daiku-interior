<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `bank_account_id` (rekening penerima pembayaran termin, PRD §4.7)
     * wasn't part of the Sprint 1 scaffolding migration for `termins` —
     * added now alongside TerminService (Sprint 4).
     */
    public function up(): void
    {
        Schema::table('termins', function (Blueprint $table) {
            $table->foreignId('bank_account_id')->nullable()->after('milestone_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('termins', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_account_id');
        });
    }
};
