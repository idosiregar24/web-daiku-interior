<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.9 (Modul Notifikasi) + §5.1 — minimal DB-backed notification
     * row (id, user_id, type, title, message, is_read, metadata). The
     * real-time broadcast half of §4.9 ("update real-time via Echo
     * private channel") is its own PRD module scheduled for
     * `.claude/plan/sprint-05.md` ("Logistics, Notifications, Analytics")
     * — NOT built here. This table exists now only because two Sprint 4
     * tasks ("QA rejection counter → notif CEO", "TerminReminderJob →
     * notif Finance") need somewhere real to write to; the bell icon in
     * AppLayout.tsx shows these on next page load, not push/real-time.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50);
            $table->string('title', 150);
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'is_read']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
