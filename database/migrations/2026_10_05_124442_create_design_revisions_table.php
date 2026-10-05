<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 12 decision #17 — every revision Marketing asks of the
     * architect, with its note (append-only history; `designs.revision_count`
     * is its running count).
     */
    public function up(): void
    {
        Schema::create('design_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->text('note');
            $table->foreignId('requested_by')->constrained('users');
            $table->timestamps();

            $table->unique(['design_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_revisions');
    }
};
