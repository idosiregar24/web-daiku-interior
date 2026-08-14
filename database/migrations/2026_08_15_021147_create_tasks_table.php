<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.5 (Task Management) + §5.1. `is_locked` backs task
     * immutability for Field Staff — see .claude/rules/security-standards.md
     * §2 (only `status`/`kendala`/`note` are editable by the assignee).
     */
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('milestone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('assignee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->date('due_date');
            $table->string('status')->default('PENDING'); // PENDING/ONPROGRESS/PENGECEKAN/DONE/OVER
            $table->string('priority')->default('MEDIUM'); // HIGH/MEDIUM/LOW
            $table->boolean('is_locked')->default(true);
            $table->decimal('rate_per_task', 12, 2)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['assignee_id', 'status']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
