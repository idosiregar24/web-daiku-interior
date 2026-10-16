<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 24 D12 — what the Pengawas finds while checking the finishing
     * on site. An open finding holds the timeline's QA and the project's
     * completion; "Jadikan Task Perbaikan" links the repair task, and the
     * finding closes when that task is DONE (or the Pengawas closes it).
     */
    public function up(): void
    {
        Schema::create('site_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('milestone_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description');
            $table->string('photo_url', 500)->nullable();
            $table->string('status', 20)->default('TERBUKA')->index();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_findings');
    }
};
