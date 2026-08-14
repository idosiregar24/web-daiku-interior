<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.4 + §5.1. `order` drives display sequence and which
     * milestone is "next" — QA-blocking logic (PRD §6.3) reads it.
     */
    public function up(): void
    {
        Schema::create('milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('target_date');
            $table->string('status')->default('PENDING'); // PENDING/IN_PROGRESS/QA_WAITING/COMPLETED/OVERDUE
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestones');
    }
};
