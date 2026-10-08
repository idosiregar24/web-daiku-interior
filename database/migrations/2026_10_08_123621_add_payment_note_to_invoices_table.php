<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 19 Sub 01 — "Tandai Klien Sudah Bayar": the proof link became
 * optional, so whoever marks the invoice paid can leave a short note for
 * Finance instead (e.g. "Transfer BCA a.n. Budi, 12 Okt").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('payment_note', 500)->nullable()->after('payment_proof_url');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('payment_note');
        });
    }
};
