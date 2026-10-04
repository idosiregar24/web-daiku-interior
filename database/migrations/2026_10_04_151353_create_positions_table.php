<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SDM (Sprint 10, decision #10): job positions (jabatan), each under one
 * division. Employees and KPI templates point here instead of a free-text
 * "position", so "Desainer" vs "Designer" can't split one job in two.
 * Name is unique per division.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('division_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['division_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
