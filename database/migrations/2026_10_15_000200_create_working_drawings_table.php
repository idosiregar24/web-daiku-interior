<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 24 D2 — "Gambar Kerja": opened with every project, assigned by
     * a Kepala Desain like a design (PIC + assistants + timeline), final
     * as soon as the team presses "Gambar Kerja Selesai" — no client ACC,
     * no invoice. Its own table rather than a `designs` row: designs are
     * one per lead and feed the design KPIs / delay count.
     */
    public function up(): void
    {
        Schema::create('working_drawings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 30)->default('MENUNGGU_PENUGASAN')->index();
            $table->foreignId('pic_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->date('start_date')->nullable();
            $table->unsignedSmallInteger('target_hari')->nullable();
            $table->date('deadline')->nullable();
            $table->json('drawing_urls')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('links_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('links_updated_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('working_drawing_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('working_drawing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['working_drawing_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('working_drawing_staff');
        Schema::dropIfExists('working_drawings');
    }
};
