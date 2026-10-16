<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 24 D3/E2 — the project's Technical Meeting: date, minutes and
     * attendees (a system user or a typed name for an outsider), recorded
     * by the PM; "TM Selesai" stamps completed_*.
     */
    public function up(): void
    {
        Schema::create('technical_meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('meeting_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('technical_meeting_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technical_meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_meeting_attendees');
        Schema::dropIfExists('technical_meetings');
    }
};
