<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §4.7 "Aset & Cicilan" — one row per installment paid on an asset.
 * Not in daiku_schema.sql (the schema only keeps the running
 * `assets.paid_install`); added so every payment has its date, bank
 * account and cash-out FinanceTransaction (PENGELUARAN / ANGSURAN).
 *
 * Append-only finance ledger (PRD §9.4): `created_at` only, no update or
 * destroy route, and every FK is restrictOnDelete — an asset, bank account
 * or transaction with payments can't be deleted out from under them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_installment_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('paid_at');
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('finance_transaction_id')->unique()->constrained()->restrictOnDelete();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['asset_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_installment_payments');
    }
};
