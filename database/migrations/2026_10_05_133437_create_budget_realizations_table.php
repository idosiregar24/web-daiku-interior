<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 12 decision #27 — what an allocated item really cost: real
     * qty × harga modal (+ optional vendor). Append-only: a correction is
     * a new row that cancels an earlier one (`reverses_id`, negative
     * amounts), never an update. `overrun_request_id` = recorded through a
     * CEO-approved overrun (#28). Finance data — never deleted, and a line
     * with realisations can't be removed (restrictOnDelete).
     */
    public function up(): void
    {
        Schema::create('budget_realizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_line_id')->constrained()->restrictOnDelete();
            $table->decimal('qty_actual', 12, 2);
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('total_cost', 15, 2);
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('budget_realizations')->restrictOnDelete();
            $table->foreignId('overrun_request_id')->nullable()->unique()->constrained('budget_overrun_requests')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['budget_line_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_realizations');
    }
};
