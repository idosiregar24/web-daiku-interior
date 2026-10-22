<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 34 — a TM is either scheduled ahead (JADWAL: date + time, the
 * invited people get an invitation and reminders H-1 / 2 hours before) or
 * held right away (LANGSUNG: the form only). Reminder stamps keep the
 * scheduler idempotent; a new date / time clears them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_meetings', function (Blueprint $table) {
            $table->string('mode', 10)->nullable()->after('meeting_date');
            $table->time('meeting_time')->nullable()->after('meeting_date');
            $table->timestamp('invited_at')->nullable()->after('mode');
            $table->timestamp('reminded_day_before_at')->nullable()->after('invited_at');
            $table->timestamp('reminded_soon_at')->nullable()->after('reminded_day_before_at');
        });
    }

    public function down(): void
    {
        Schema::table('technical_meetings', function (Blueprint $table) {
            $table->dropColumn(['mode', 'meeting_time', 'invited_at', 'reminded_day_before_at', 'reminded_soon_at']);
        });
    }
};
