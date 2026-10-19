<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 24 D11 — a project's progress is its actual activity
     * (Project::actualProgress()), so a progress log is now a PM's note
     * without a typed percentage. Old rows keep theirs (append-only).
     */
    public function up(): void
    {
        Schema::table('progress_logs', function (Blueprint $table) {
            $table->unsignedTinyInteger('percentage')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('progress_logs')->whereNull('percentage')->update(['percentage' => 0]);

        Schema::table('progress_logs', function (Blueprint $table) {
            $table->unsignedTinyInteger('percentage')->nullable(false)->change();
        });
    }
};
