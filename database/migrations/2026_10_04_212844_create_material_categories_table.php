<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 11 decision #11 — material categories become a master
     * (Data Master → Kategori Material, SUPERADMIN), replacing the free
     * text `materials.category`. `code_prefix` drives the generated item
     * code (KYP-0012, §5.5 Lapis 1). A category in use is deactivated,
     * never deleted.
     */
    public function up(): void
    {
        Schema::create('material_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->string('code_prefix', 5)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_categories');
    }
};
