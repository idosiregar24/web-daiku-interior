<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.3 + daiku_schema.sql `quotation_approvals` — append-only log
     * of each CEO/PM approve-or-reject decision (dual approval, PRD
     * "Approval harus berurutan: CEO approve dulu, baru PM"). Table only,
     * not wired to any route/service yet — the approval flow itself is
     * .claude/plan/sprint-03.md Week 5 ("Quotation approval flow: CEO
     * approve → PM approve (sequential)"), created now alongside its
     * sibling tables per this week's "Database migration: quotations,
     * quotation_items, quotation_approvals" task.
     *
     * `approver_role` stores 'CEO'/'PM' (the Spatie role slugs already
     * used everywhere else in this codebase), not daiku_schema.sql's
     * literal 'PROJECT_MANAGER' — kept consistent with how the rest of
     * the app names the PM role.
     */
    public function up(): void
    {
        Schema::create('quotation_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approver_id')->constrained('users')->cascadeOnDelete();
            $table->string('approver_role');
            $table->string('status');
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('quotation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_approvals');
    }
};
