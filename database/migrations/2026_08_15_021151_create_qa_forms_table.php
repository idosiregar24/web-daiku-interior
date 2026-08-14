<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.6 (QA) + §5.1. One QA form per milestone (unique), created
     * automatically by the system when a PM marks a milestone done — see
     * PRD §6.3. `checklist_data` shape: [{label, passed, note}].
     */
    public function up(): void
    {
        Schema::create('qa_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('milestone_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('PENDING'); // PENDING/APPROVED/REJECTED
            $table->json('checklist_data')->nullable();
            $table->unsignedTinyInteger('rejection_count')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_forms');
    }
};
