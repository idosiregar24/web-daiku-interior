<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 32 — how one Kepala Tim's share of the Upah Tukang splits among
 * its people on one project (a copy of a "Tim Tukang" template, adjustable
 * per project). `team_label` = the Kepala Tim label of the payments'
 * breakdown (ProjectWorkItem::teamLeadLabel()). A help for the Kepala Tim —
 * the money is still paid to the Pengawas / Kepala Tim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_wage_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('team_label');
            $table->foreignId('work_team_id')->nullable()->constrained()->nullOnDelete();
            $table->json('members');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'team_label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_wage_splits');
    }
};
