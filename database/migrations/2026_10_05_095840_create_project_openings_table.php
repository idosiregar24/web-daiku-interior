<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 12 decision #19 — the client approving a RAB Proyek queues a
     * "Buka Proyek" for the CEO (MENUNGGU_CEO); the CEO opening it creates
     * the project and its termins from the approved payment scheme
     * (DIBUKA). One opening per quotation.
     */
    public function up(): void
    {
        Schema::create('project_openings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->unique()->constrained();
            $table->foreignId('lead_id')->constrained();
            $table->string('status', 20); // MENUNGGU_CEO | DIBUKA
            $table->foreignId('opened_by')->nullable()->constrained('users');
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('project_id')->nullable()->constrained();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_openings');
    }
};
