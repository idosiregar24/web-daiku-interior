<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 9 decision #5 — an account's running balance is derived from
     * its transactions (BankAccount::scopeWithBalance()), never stored.
     * The one stored figure left is the saldo awal before the first
     * transaction recorded in the system, which is what Data Master's
     * form already labelled this field ("Saldo Awal"), so the existing
     * values keep their meaning. Plain rename: Laravel 11's native
     * renameColumn() works on MySQL 8 and SQLite (tests) without
     * doctrine/dbal.
     */
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->renameColumn('balance', 'opening_balance');
        });
    }

    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->renameColumn('opening_balance', 'balance');
        });
    }
};
