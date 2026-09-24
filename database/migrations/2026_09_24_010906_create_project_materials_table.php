<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.8 "Kebutuhan Material Proyek" + §5.1 / daiku_schema.sql
     * `project_materials`. `qty_used` is never written directly — it
     * accumulates from stock-out movements (StockService::stockOut()), so
     * it always reconciles with the stock ledger. One row per
     * project+material; planning the same material again updates it.
     */
    public function up(): void
    {
        Schema::create('project_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // restrictOnDelete, not cascade: a material with project
            // history must not silently erase that history when deleted.
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty_planned')->default(0);
            $table->unsignedInteger('qty_used')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'material_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_materials');
    }
};
