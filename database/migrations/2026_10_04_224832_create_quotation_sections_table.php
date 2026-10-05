<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 12 decision #11 — RAB items are grouped by bagian pekerjaan
     * (Excel "RAB_KOPI OZ ARIFIN") and carry their dimensions P × T/L.
     * Items from before Sprint 12 have no section and show under "Umum".
     */
    public function up(): void
    {
        Schema::create('quotation_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['quotation_id', 'sort_order']);
        });

        Schema::table('quotation_items', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable()->after('quotation_id')->constrained('quotation_sections')->cascadeOnDelete();
            $table->decimal('dim_length', 10, 2)->nullable()->after('description');
            $table->decimal('dim_width_height', 10, 2)->nullable()->after('dim_length');
        });
    }

    public function down(): void
    {
        Schema::table('quotation_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('section_id');
            $table->dropColumn(['dim_length', 'dim_width_height']);
        });

        Schema::dropIfExists('quotation_sections');
    }
};
