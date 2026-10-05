<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 12 decision #12 — the DP/termin scheme is drawn up by the
     * Estimator inside the quotation (max 6 rows incl. DP, D1) and the
     * client approves it with the RAB. Percentages sum to 100; amounts are
     * derived from the quotation total server-side and always sum to it.
     * A row is triggered upfront (DI_MUKA), on a date, at a milestone, or
     * when the project completes. Buka Proyek (Sub 7) turns them into termins.
     */
    public function up(): void
    {
        Schema::create('quotation_payment_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->string('label', 100);
            $table->decimal('percentage', 5, 2);
            $table->decimal('amount', 15, 2);
            $table->string('trigger', 20); // App\Enums\PaymentTermTrigger
            $table->date('due_date')->nullable();
            $table->string('milestone_name', 150)->nullable();
            $table->timestamps();

            $table->unique(['quotation_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_payment_terms');
    }
};
