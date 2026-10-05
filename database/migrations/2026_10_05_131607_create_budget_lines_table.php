<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 12 decision #24 — one RAB item allocated to a budget post. The
     * item's figures are copied (the RAB Fix is frozen, and Sub 10's
     * realisations compare against this budget); `quotation_item_id` is
     * UNIQUE — one item, one post. Nullable for lines that don't come from
     * a RAB item.
     */
    public function up(): void
    {
        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_item_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('qty', 12, 2);
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('unit_price', 15, 2);
            // The line's budget (anggaran) = the RAB item's total.
            $table->decimal('sell_price', 15, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
    }
};
