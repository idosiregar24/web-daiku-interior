<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 20 Sub 04 — finished work shown on the public company profile.
 * Never the client's name, address or contract value: `location_label` is
 * a district ("Panam"), and publishing needs `client_consent`
 * (PortfolioService::publish()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_items', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->string('slug', 180)->unique();
            $table->string('project_type', 30);
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location_label', 100)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('summary', 300)->nullable();
            $table->text('description')->nullable();
            // No DB foreign key (the photos table points back here);
            // PortfolioPhotoService clears it when the cover is deleted.
            $table->unsignedBigInteger('cover_photo_id')->nullable();
            $table->boolean('is_published')->default(false);
            $table->boolean('client_consent')->default(false);
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_published', 'sort_order']);
            $table->index(['project_type', 'is_published']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_items');
    }
};
