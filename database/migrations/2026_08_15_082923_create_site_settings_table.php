<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Site Settings — general application/company profile config, not a
     * PRD-named table. Added on request, CEO + SUPERADMIN only (see
     * RoleMiddleware for the SUPERADMIN bypass). Deliberately a singleton
     * (one row, no CRUD list) — SiteSetting::current() gets-or-creates it.
     *
     * Not to be confused with PRD §4.7's `finance_allocation_configs`
     * (CEO/FINANCE-editable percentage allocations) — that's a distinct,
     * Finance-module concept, not covered here.
     */
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('site_name')->default('Daiku Interior');
            $table->text('company_address')->nullable();
            $table->string('company_phone')->nullable();
            $table->string('company_email')->nullable();
            $table->string('company_logo_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
