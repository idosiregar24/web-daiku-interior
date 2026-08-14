<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.5/§4.7 (Dana Family Gathering) + §5.1. Append-only ledger —
     * per .claude/rules/database-standards.md §3, no soft delete, no
     * `destroy` route/policy ever.
     */
    public function up(): void
    {
        Schema::create('family_gathering_fund', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // INCOME/EXPENSE
            $table->decimal('amount', 12, 2);
            $table->text('description')->nullable();
            $table->foreignId('source_penalty_id')->nullable()->constrained('penalties')->nullOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_gathering_fund');
    }
};
