<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 12 decisions #20–#21 — every invoice is issued by Marketing
     * (Jasa Survey, Jasa Desain, DP, termin, pelunasan, tambahan); Finance
     * verifies that the money really came in. Verification writes one
     * PEMASUKAN transaction (`finance_transaction_id` — at most once) and
     * fires InvoiceVerified, which moves the next step (survey ready,
     * design unlocked, termin paid, allocation opened). A rejected proof
     * sends it back to DITERBITKAN with the reason kept. Finance data: no
     * soft deletes, never deleted.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('lead_id')->constrained();
            $table->foreignId('project_id')->nullable()->constrained();
            $table->foreignId('quotation_id')->nullable()->constrained();
            $table->foreignId('termin_id')->nullable()->constrained();
            $table->string('type', 20); // App\Enums\InvoiceType
            $table->decimal('amount', 15, 2);
            $table->date('due_date');
            $table->string('status', 25); // App\Enums\InvoiceStatus
            $table->foreignId('issued_by')->constrained('users');
            $table->timestamp('issued_at');
            $table->string('payment_proof_url', 500)->nullable();
            $table->foreignId('proof_submitted_by')->nullable()->constrained('users');
            $table->timestamp('proof_submitted_at')->nullable();
            $table->foreignId('bank_account_id')->nullable()->constrained();
            $table->date('paid_date')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('finance_transaction_id')->nullable()->unique()->constrained();
            $table->string('reject_reason', 1000)->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users');
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'issued_at']);
            $table->index(['quotation_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
