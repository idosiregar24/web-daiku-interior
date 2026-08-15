<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.2 (Modul Desain) + daiku_schema.sql `designs` (more detailed
     * than PRD §5.1's sketch — jenis_project, target_hari/deadline/
     * delay_hari, problem — see .claude/plan/README.md "Schema discovery").
     * bigint PK, not the schema file's ULID, per the precedent already
     * set for leads/projects/etc.
     */
    public function up(): void
    {
        Schema::create('designs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('pic_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('jenis_project')->nullable();
            $table->string('status')->default('BRIEF');
            $table->unsignedInteger('target_hari')->nullable();
            $table->date('start_date')->nullable();
            $table->date('deadline')->nullable();
            $table->unsignedInteger('delay_hari')->default(0);
            $table->json('design_urls')->nullable();
            $table->text('brief_note')->nullable();
            $table->text('problem')->nullable();
            $table->boolean('client_acc')->default(false);
            $table->date('acc_date')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('designs');
    }
};
