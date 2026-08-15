<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master Data — Sumber Lead. PRD §4.1 lists these as a fixed set
     * (Instagram, TikTok, Referral, Walk-in, WhatsApp, Marketplace, Iklan
     * Sosmed, Website) hardcoded into `leads.source` (plain string, no
     * FK). This table makes that list SuperAdmin-editable; `leads.source`
     * itself is intentionally left as a free string for now — switching
     * it to a `lead_source_id` FK would touch the already-shipped CRM
     * Lead module and is a separate follow-up, not done here.
     */
    public function up(): void
    {
        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_sources');
    }
};
