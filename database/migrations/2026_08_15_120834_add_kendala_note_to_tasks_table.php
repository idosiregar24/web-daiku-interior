<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.5 "Field Kendala & Note" + daiku_schema.sql `tasks.kendala`/
     * `tasks.note` — missing from the original tasks migration (Sprint 2
     * Week 3) despite that migration's own docblock already promising
     * them ("hanya status/kendala/note yang bisa diubah"); added now
     * because TaskController::updateStatus() (Week 4) is the feature that
     * actually needs them.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->text('kendala')->nullable()->after('is_locked');
            $table->text('note')->nullable()->after('kendala');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['kendala', 'note']);
        });
    }
};
