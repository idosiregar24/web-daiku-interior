<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 14 Sub 01 — what Marketing (or the PM, for a RAB Tambahan)
     * hands the Estimator with a RAB request: reference links and photos,
     * one row each. Photos live on the private `local` disk; internal
     * material only, never shown to the client.
     */
    public function up(): void
    {
        Schema::create('quotation_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // LINK | PHOTO
            $table->string('url', 500)->nullable();
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['quotation_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_references');
    }
};
