<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 20 Sub 05 — the public company profile's own text, edited in
 * Pengaturan Situs → "Profil Publik". Empty columns fall back to the
 * placeholders in App\Support\CompanyProfile\Placeholder.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'public_tagline', 'hero_headline', 'hero_subheadline', 'about_text',
        'founded_year', 'stat_projects', 'stat_cities', 'service_area_text',
        'whatsapp_phone', 'whatsapp_greeting', 'maps_embed_url', 'opening_hours',
        'google_site_verification', 'hero_image_path',
    ];

    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->string('public_tagline', 120)->nullable();
            $table->string('hero_headline', 120)->nullable();
            $table->string('hero_subheadline', 300)->nullable();
            $table->text('about_text')->nullable();
            $table->unsignedSmallInteger('founded_year')->nullable();
            $table->unsignedInteger('stat_projects')->nullable();
            $table->unsignedSmallInteger('stat_cities')->nullable();
            $table->string('service_area_text', 300)->nullable();
            $table->string('whatsapp_phone', 20)->nullable();
            $table->string('whatsapp_greeting', 200)->nullable();
            $table->string('maps_embed_url', 1000)->nullable();
            $table->string('opening_hours', 200)->nullable();
            $table->string('google_site_verification', 100)->nullable();
            $table->string('hero_image_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
