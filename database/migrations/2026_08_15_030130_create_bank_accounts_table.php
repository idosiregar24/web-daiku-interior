<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master Data — Rekening Bank. PRD §4.7 "Multi-Rekening" (BCA 5835,
     * BCA 4342, Mandiri, BRI, BNI, Mandiri PT, dll) + confirmed against
     * `.claude/File Skema/Daiku v1.0.0/daiku_schema.sql`'s `bank_accounts`
     * table. Every `finance_transactions`/`termins` row is meant to
     * reference one of these (PRD §4.7 "setiap transaksi wajib
     * mencantumkan rekening bank") — those FKs land with the Finance
     * module itself (Sprint 4), not here.
     *
     * Column types (bigint PK, not the ULID/VARCHAR(26) the schema file
     * uses) intentionally match every other table already shipped in
     * this codebase rather than the schema file, to avoid a disruptive
     * PK-strategy change across already-tested modules — see
     * .claude/plan/README.md for the full note on this discrepancy.
     */
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name'); // BCA, Mandiri, BRI, BNI
            $table->string('account_no');
            $table->string('label')->unique(); // "BCA 5835", "Mandiri PT"
            $table->decimal('balance', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
