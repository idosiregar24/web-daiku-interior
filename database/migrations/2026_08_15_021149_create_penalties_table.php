<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.5/§6.5 (DailyPenaltyJob) + §5.1. `reference_id` is
     * polymorphic-by-`type` (a task_id for TASK_OVERDUE, no fixed target
     * for DAILY_FORM_MISSING) rather than a real FK — see PRD's own
     * schema note "task_id atau daily_form tanggal".
     */
    public function up(): void
    {
        Schema::create('penalties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('users')->cascadeOnDelete();
            $table->string('type'); // DAILY_FORM_MISSING, TASK_OVERDUE
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->decimal('amount', 10, 2)->default(50000);
            $table->date('date_occurred');
            $table->boolean('is_deducted')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['staff_id', 'date_occurred']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penalties');
    }
};
