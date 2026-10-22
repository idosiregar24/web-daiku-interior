<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 29 Sub 13 (I1) — "Aktivitas Harian": what an employee (every
 * account except a Tukang) did today, free text, optionally tied to a
 * project. A day may hold several entries; each is editable only on its
 * own day (DailyActivityPolicy). HR checks a day later
 * (daily_activity_reviews) — the base of the KPI indicators of Sub 14.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->text('description');
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_activities');
    }
};
