<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 32 — "Tim Tukang" templates (File D: Tim Putra, Tim Evan, Tim
 * Yusup): a team's members with their position and weight, saved once and
 * picked per project. `members` = [{name, level, weight, company}] — a
 * template, edited as a whole (WageSplitService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_teams', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('leader_name', 120)->nullable();
            $table->json('members');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_teams');
    }
};
