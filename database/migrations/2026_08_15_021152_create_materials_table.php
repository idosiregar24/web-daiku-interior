<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.8 (Logistik) + §5.1. Margin (`sell_price - cost_price`) is
     * computed, not stored — an accessor on the Material model, not a
     * column here.
     */
    public function up(): void
    {
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('unit');
            $table->decimal('cost_price', 12, 2);
            $table->decimal('sell_price', 12, 2);
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('min_stock')->default(0); // alert threshold
            $table->string('category')->nullable();
            $table->timestamps();

            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materials');
    }
};
