<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 23 K12d — how many times "Tandai Klien Sudah Bayar" was sent
     * for the invoice (1 = first, 2 = after Finance rejected once, …), so
     * the photos of the current submission are told apart from the earlier
     * ones (invoice_payment_proofs.attempt). Existing invoices that already
     * had a submission start at 1 (no photos existed before this sprint).
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedSmallInteger('proof_attempt')->default(0)->after('payment_note');
        });

        DB::table('invoices')->whereNotNull('proof_submitted_at')->update(['proof_attempt' => 1]);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('proof_attempt');
        });
    }
};
