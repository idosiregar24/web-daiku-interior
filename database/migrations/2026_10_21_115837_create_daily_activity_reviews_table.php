<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 29 Sub 13 (I2) — HR's check of one employee's day ("Periksa
 * Aktivitas"): SESUAI / PERLU_KLARIFIKASI / TIDAK_MENGISI. Keyed by the
 * person-day (`user_id` + `date`), not by an activity row: a day can hold
 * several entries, and a day without any ("Tidak mengisi") has none to
 * point at. Append-only — a later check is a new row, the newest counts
 * (DailyActivityReview refuses update/delete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_activity_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('status', 30); // App\Enums\DailyActivityReviewStatus
            $table->text('note')->nullable();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_activity_reviews');
    }
};
