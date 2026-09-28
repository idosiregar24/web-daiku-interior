<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Web customization (CEO + SUPERADMIN, Pengaturan Situs): uploaded
     * brand assets replace the old free-text `company_logo_url`, plus the
     * tagline and login-page headline shown across the app. Asset columns
     * hold paths on the private `local` disk — served through
     * BrandingAssetController, never linked directly.
     */
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->string('site_tagline')->nullable()->after('site_name');
            $table->string('login_headline')->nullable()->after('site_tagline');
            $table->string('logo_path')->nullable()->after('company_email');
            $table->string('favicon_path')->nullable()->after('logo_path');
            $table->string('login_image_path')->nullable()->after('favicon_path');
            $table->dropColumn('company_logo_url');
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->string('company_logo_url')->nullable()->after('company_email');
            $table->dropColumn(['site_tagline', 'login_headline', 'logo_path', 'favicon_path', 'login_image_path']);
        });
    }
};
