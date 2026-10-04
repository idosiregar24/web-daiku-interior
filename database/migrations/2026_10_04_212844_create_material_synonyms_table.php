<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 11 §5.5 Lapis 2 — words that mean the same item ("plywood" →
     * "triplek"). Applied when the catalog builds an item's match_key, so
     * "Plywood 17mm" and "Triplek 17 mm" collide as one item. Both sides
     * are stored normalized (lower case, single spaces). SUPERADMIN-managed.
     */
    public function up(): void
    {
        Schema::create('material_synonyms', function (Blueprint $table) {
            $table->id();
            $table->string('term', 50)->unique();
            $table->string('canonical', 50);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_synonyms');
    }
};
