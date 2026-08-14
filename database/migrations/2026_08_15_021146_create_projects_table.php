<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.4 (Project Management) + §5.1. Created from a Lead once it
     * reaches DEAL_DESAIN → Deal (PRD §4.4 business rules).
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('pm_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('ACTIVE'); // ACTIVE/COMPLETED/ON_HOLD/CANCELLED
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->decimal('contract_value', 15, 2);
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
