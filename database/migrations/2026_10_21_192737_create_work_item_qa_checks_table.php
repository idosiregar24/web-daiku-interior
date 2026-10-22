<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 29 Sub 12 (G1) — "QA per item RAB": the Pengawas's decision on an
 * item that reached 100 % — LOLOS or PERBAIKAN (with the finding it opened).
 * Append-only; an item's QA status is read from its newest row
 * (WorkItemQaService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_item_qa_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_work_item_id')->constrained()->cascadeOnDelete();
            $table->string('decision', 20);
            $table->string('note', 1000)->nullable();
            // Optional evidence: a link (Drive/WA) and/or one photo on the private disk.
            $table->string('photo_url', 500)->nullable();
            $table->string('photo_path')->nullable();
            // PERBAIKAN — the finding it opened ("Jadikan Task Perbaikan", Sprint 24 D12).
            $table->foreignId('site_finding_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('checked_by')->constrained('users');
            $table->timestamps();

            $table->index(['project_id', 'project_work_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_qa_checks');
    }
};
