<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.7 "Alokasi Persentase Otomatis" / daiku_schema.sql
     * `finance_allocation_configs`. bigint PK (Sprint 8 decision #1) and
     * `updated_at` added — rows are edited in place (CEO/Finance), with
     * every change captured by AuditLogService. Rows are deactivated via
     * `is_active`, never deleted.
     */
    public function up(): void
    {
        Schema::create('finance_allocation_configs', function (Blueprint $table) {
            $table->id();
            $table->string('label', 50)->unique();
            $table->decimal('percentage', 5, 2);
            $table->string('kategori', 50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_allocation_configs');
    }
};
