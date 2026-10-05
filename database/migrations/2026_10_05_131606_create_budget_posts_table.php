<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 12 decision #24 — "Alokasi Dana Proyek" (code name
     * ProjectBudget, not to be confused with Finance's Sprint 8
     * `finance_allocation_configs`): the PM's freely named budget posts of
     * a project (Interior, Listrik, Percetakan, Mural, …).
     */
    public function up(): void
    {
        Schema::create('budget_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['project_id', 'name']);
            $table->index(['project_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_posts');
    }
};
