<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 9 decision #10 — penalties are paid by the tukang manually
     * (cash/transfer), never deducted from wages. Finance records the
     * payment (PenaltyCollectionService): the existing `is_deducted` flag
     * now means "lunas", and these columns say when, by whom and through
     * which PEMASUKAN PENALTY_COLLECT transaction it was paid.
     */
    public function up(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->timestamp('collected_at')->nullable()->after('is_deducted');
            $table->foreignId('collected_by')->nullable()->after('collected_at')->constrained('users')->nullOnDelete();
            $table->foreignId('finance_transaction_id')->nullable()->after('collected_by')->constrained()->nullOnDelete();

            // Penalty page: per-tukang outstanding + the Belum Dibayar/Lunas filter.
            $table->index(['staff_id', 'is_deducted']);
        });
    }

    public function down(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->dropIndex(['staff_id', 'is_deducted']);
            $table->dropConstrainedForeignId('finance_transaction_id');
            $table->dropConstrainedForeignId('collected_by');
            $table->dropColumn('collected_at');
        });
    }
};
