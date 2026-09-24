<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §9.4 Audit Trail + daiku_schema.sql `audit_logs` (bigint PK like
     * every other shipped table — see CLAUDE.md golden rule #1). Append-
     * only: no updated_at, no update/delete route, and App\Models\AuditLog
     * refuses updates/deletes at the model layer too. `user_id` is
     * nullOnDelete (schema: ON DELETE SET NULL) so history outlives an
     * account; null also means "Sistem" (scheduled jobs).
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100);
            $table->string('model_type', 50);
            $table->unsignedBigInteger('model_id');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['model_type', 'model_id']);
            $table->index(['action', 'created_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
