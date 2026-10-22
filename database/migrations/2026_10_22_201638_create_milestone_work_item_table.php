<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 35 — the Timeline is the one schedule: the PM picks, per timeline,
 * the RAB items worked on in it. An item's dates on the Lembar Progres come
 * from its timelines (project_work_items.production_start/end become a cache
 * of them — WorkItemService::syncTimelineSchedule()). An item may sit in
 * several timelines (produksi, pemasangan), a timeline may hold none
 * ("Pemesanan Bahan").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milestone_work_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('milestone_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_work_item_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['milestone_id', 'project_work_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestone_work_item');
    }
};
