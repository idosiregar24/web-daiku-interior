<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.7 + daiku_schema.sql reconciliation, deferred here explicitly
     * by the Sprint 1 migration's own comment ("Sprint 4"). `type` keeps
     * its existing column but its *values* narrow from here on to the
     * PEMASUKAN/PENGELUARAN pair (App\Enums\FinanceTransactionType) —
     * `kategori` (App\Enums\FinanceCategory) now carries the finer
     * classification that used to live loosely in `type`
     * (OVERTIME_PAY → type=PENGELUARAN, kategori=LEMBUR_BONUS, etc — see
     * OvertimeService::financeDecision()). `bank_account_id` nullable at
     * the DB level even though PRD §4.7 says "wajib" — enforced at the
     * Form Request layer for manually-created transactions instead;
     * left nullable here so already-shipped writers (OvertimeService)
     * that don't yet collect a bank account don't break.
     */
    public function up(): void
    {
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->string('kategori')->nullable()->after('type');
            $table->foreignId('bank_account_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_account_id');
            $table->dropColumn('kategori');
        });
    }
};
