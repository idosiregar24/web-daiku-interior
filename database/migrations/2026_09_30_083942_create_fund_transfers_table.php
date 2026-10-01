<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.7 "Pindah Dana" between the company's own accounts (Sprint 9
     * decision #5). Not in daiku_schema.sql, which only has the
     * PINDAH_DANA kategori: this row is the business record both
     * finance_transactions legs point back to (`kategori = PINDAH_DANA`,
     * `reference_id = fund_transfers.id`), the same way loans and supplier
     * debts are linked — see FundTransferService. Append-only finance
     * record (PRD §9.4): `created_at` only, no destroy route, FKs restrict
     * deletes.
     */
    public function up(): void
    {
        Schema::create('fund_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->foreignId('to_bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('date');
            $table->string('description', 150);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_transfers');
    }
};
