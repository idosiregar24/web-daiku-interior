<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 20 Sub 06 — one public page per service (`/layanan/{slug}`).
 * Rows and slugs come from App\Support\CompanyProfile\ServiceCatalog;
 * only the text is edited (ServicePageService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_pages', function (Blueprint $table) {
            $table->id();
            $table->string('project_type', 30)->unique();
            $table->string('slug', 120)->unique();
            $table->string('title', 120);
            $table->string('headline', 150);
            $table->text('intro');
            $table->text('body');
            $table->json('highlights')->nullable();
            $table->json('faqs')->nullable();
            $table->string('meta_description', 160)->nullable();
            $table->string('hero_image')->nullable();
            $table->unsignedSmallInteger('hero_width')->nullable();
            $table->unsignedSmallInteger('hero_height')->nullable();
            $table->boolean('is_published')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_pages');
    }
};
