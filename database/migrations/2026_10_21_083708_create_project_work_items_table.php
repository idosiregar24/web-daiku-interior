<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 29 Sub 08 (B2) — one row per RAB item of a project (RAB Proyek +
 * approved RAB Tambahan): the Pengawas's Satuan Upah and the Kepala Tim who
 * does it. Subtotal, bobot and total are computed (WorkItemService), never
 * stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_work_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_item_id')->unique()->constrained()->cascadeOnDelete();
            // Kepala Tim: a user or a typed name (outsider/vendor) — like projects.team_lead_*.
            $table->foreignId('team_lead_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('team_lead_name')->nullable();
            // Null = no wage on this item ("tanpa upah" — finished goods, accessories).
            $table->decimal('unit_wage', 15, 2)->nullable();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_work_items');
    }
};
