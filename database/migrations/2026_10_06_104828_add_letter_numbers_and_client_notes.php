<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 15 Sub 02 — letter numbers shared by offers and invoices
     * (`letter_sequences`, one running number per year), the offer's own
     * number once sent, and the RAB's "Catatan" for the client.
     */
    public function up(): void
    {
        Schema::create('letter_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->string('letter_number', 60)->nullable()->after('version');
            $table->text('client_notes')->nullable()->after('request_note');
        });

        // "378/INV/Daiku/VIII/2026" — room for a longer company code.
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('number', 60)->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('number', 30)->change();
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn(['letter_number', 'client_notes']);
        });

        Schema::dropIfExists('letter_sequences');
    }
};
