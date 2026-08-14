<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.5 (Daily Form Wajib) + §5.1 + §6.5 (penalty job reads this).
     * One submission per task per day — enforced by the unique index, not
     * just application logic.
     */
    public function up(): void
    {
        Schema::create('daily_task_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('users')->cascadeOnDelete();
            $table->date('work_date');
            $table->string('status_update'); // TaskStatus at time of submission
            $table->text('kendala')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->useCurrent();

            $table->unique(['task_id', 'work_date']);
            $table->index(['staff_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_task_forms');
    }
};
