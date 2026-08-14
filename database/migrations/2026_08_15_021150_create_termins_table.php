<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.7/§6.4 (Logika Termin Sabtu) + §5.1. `status` is left as a
     * plain string for now — the actual state machine lands with
     * TerminService in Sprint 4 (.claude/plan/sprint-04.md).
     */
    public function up(): void
    {
        Schema::create('termins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('milestone_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('termin_number');
            $table->unsignedTinyInteger('percentage');
            $table->decimal('amount', 15, 2);
            $table->date('scheduled_date'); // selalu Sabtu — lihat PRD §6.4 getNextSaturday()
            $table->string('status')->default('PENDING');
            $table->string('invoice_url')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('termins');
    }
};
