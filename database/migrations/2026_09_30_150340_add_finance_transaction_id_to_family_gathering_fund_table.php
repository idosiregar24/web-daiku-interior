<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 9 decision #10 — a "Penggunaan Dana" (EXPENSE row) now pays
     * out of a bank account through a PENGELUARAN FinanceTransaction, so
     * per-account balances stay right (PRD §4.7 "Setiap transaksi wajib
     * mencantumkan rekening"). This links the fund row to that
     * transaction; INCOME rows (and legacy expenses) leave it null.
     */
    public function up(): void
    {
        Schema::table('family_gathering_fund', function (Blueprint $table) {
            $table->foreignId('finance_transaction_id')->nullable()->after('source_penalty_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('family_gathering_fund', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finance_transaction_id');
        });
    }
};
