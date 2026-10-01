<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.3 "Versi Revisi: Sistem menyimpan riwayat revisi quotation"
     * (Sprint 9 decision #3). Not in daiku_schema.sql, which only has the
     * `quotations.version` counter. One row per closed version: every
     * rejection that sends a quotation back to DRAFT (CEO, PM or the
     * client) freezes that version's RAB items + total here before
     * `quotations.version` is bumped — see QuotationService::closeVersion().
     *
     * Append-only like `audit_logs`: `created_at` only, no update/delete
     * route, and App\Models\QuotationRevision refuses both at the model
     * layer. `reason` is a string (CEO_REJECTED/PM_REJECTED/
     * CLIENT_REJECTED), not a native ENUM — database-standards.md §2.
     */
    public function up(): void
    {
        Schema::create('quotation_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->decimal('total_amount', 15, 2);
            $table->json('items');
            $table->string('reason', 30);
            $table->text('note')->nullable();
            $table->foreignId('closed_by')->constrained('users');
            $table->timestamp('created_at')->nullable();

            // A version is closed exactly once — also the backstop against
            // a double-submitted rejection.
            $table->unique(['quotation_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_revisions');
    }
};
