<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.4 + §5.1. PM's daily progress log per project — append-only,
     * no `updated_at`.
     */
    public function up(): void
    {
        Schema::create('progress_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('logged_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('percentage'); // 0-100
            $table->text('description');
            $table->json('design_urls')->nullable();
            $table->date('log_date');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['project_id', 'log_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('progress_logs');
    }
};
