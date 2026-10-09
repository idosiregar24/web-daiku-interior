<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sprint 20 Sub 04 — WebP files on the `public` disk (PortfolioPhotoService). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_item_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('path_thumb');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->string('alt', 200)->nullable();
            $table->string('caption', 200)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['portfolio_item_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_photos');
    }
};
