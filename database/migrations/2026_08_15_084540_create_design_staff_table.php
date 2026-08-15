<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.2 "PIC & Sub-Staff" — sub-staff who help on one design
     * project, each with a free-text role note (e.g. "3D modeling").
     */
    public function up(): void
    {
        Schema::create('design_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role_note')->nullable();
            $table->timestamps();

            $table->unique(['design_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_staff');
    }
};
