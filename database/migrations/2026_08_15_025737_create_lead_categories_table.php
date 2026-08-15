<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master Data — Kategori Customer. PRD §4.1 lists a fixed set
     * (RESIDENTIAL, KOMERSIAL, DEVELOPER, KONTRAKTOR, LAINNYA) validated
     * via Rule::in() on `leads.category` (plain string, no FK). Same
     * deal as lead_sources: SuperAdmin-editable list here, `leads.category`
     * itself not migrated to an FK in this pass.
     */
    public function up(): void
    {
        Schema::create('lead_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_categories');
    }
};
