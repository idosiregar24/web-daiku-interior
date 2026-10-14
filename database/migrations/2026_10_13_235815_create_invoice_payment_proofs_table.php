<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 23 K12d — payment proof photos Marketing attaches to "Tandai
     * Klien Sudah Bayar" (optional, max 3 per submission). Append-only
     * (App\Models\InvoicePaymentProof refuses update/delete): when Finance
     * rejects and Marketing sends again, the earlier photos stay as history
     * under their `attempt` (= invoices.proof_attempt of that submission).
     * Files live on the private `local` disk (WebP ≤ 2000 px, metadata
     * stripped) and are served only through `finance.invoices.proofs.show`.
     */
    public function up(): void
    {
        Schema::create('invoice_payment_proofs', function (Blueprint $table) {
            $table->id();
            // Invoices are finance data and never deleted — no cascade.
            $table->foreignId('invoice_id')->constrained();
            $table->unsignedSmallInteger('attempt');
            $table->string('path');
            $table->string('path_thumb');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedInteger('size');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();

            $table->index(['invoice_id', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payment_proofs');
    }
};
