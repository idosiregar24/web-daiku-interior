<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 29 Sub 09 — the draft of a project's next pengecekan (one row per
 * project): the Pengawas or the Admin Proyek fills it (saved automatically),
 * only the Pengawas sends it. Not a record — it is removed once the
 * pengecekan is sent (the rows then live in work_item_progress).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_item_progress_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            // {"<work item id>": {"percent": 20, "note": null}, …}
            $table->json('entries')->nullable();
            $table->text('notes')->nullable();
            $table->json('links')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_progress_drafts');
    }
};
