<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 23 K9 / K11 — notes on one RAB version that never move the
     * flow: the CEO's information-only note (CEO), the client's reason on
     * the public link's "Tidak Setuju" (CLIENT — no internal author), and
     * what Marketing adds before forwarding that rejection to the
     * Estimator (MARKETING). Append-only like `audit_logs`
     * (App\Models\QuotationNote refuses updates and deletes).
     */
    public function up(): void
    {
        Schema::create('quotation_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('kind', 20); // CEO | CLIENT | MARKETING
            $table->foreignId('author_id')->nullable()->constrained('users');
            $table->text('note');
            $table->timestamps();

            $table->index(['quotation_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_notes');
    }
};
