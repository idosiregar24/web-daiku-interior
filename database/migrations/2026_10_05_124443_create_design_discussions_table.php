<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 12 decision #18 / D6 — the Arsitek ↔ Estimator thread of a
     * design: text plus an optional link, optionally about one RAB of the
     * same lead (`quotation_id`). Shown on the design and on its lead's
     * quotations.
     */
    public function up(): void
    {
        Schema::create('design_discussions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->text('body');
            $table->string('attachment_url', 2048)->nullable();
            $table->timestamps();

            $table->index(['design_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_discussions');
    }
};
