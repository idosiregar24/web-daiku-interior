<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 12 decision #8 — PM / Asisten PM (and optionally the CEO)
     * mark every RAB item ✔ OK / ✘ SALAH, with a note for ✘. Append-only
     * (App\Models\QuotationItemReview refuses updates/deletes): it is the
     * data behind the Estimator's and PM's review KPIs (decision #9).
     * Items are rewritten on every RAB save, so the row keeps its own
     * copy of the item text and section; the FK only links the version
     * being reviewed and is nulled once that item row is replaced.
     */
    public function up(): void
    {
        Schema::create('quotation_item_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('quotation_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_description', 255);
            $table->string('section_name', 150)->nullable();
            $table->string('stage', 10); // PM | CEO
            $table->foreignId('reviewer_id')->constrained('users');
            $table->string('verdict', 10); // OK | SALAH
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['quotation_id', 'version']);
            $table->index(['reviewer_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_item_reviews');
    }
};
