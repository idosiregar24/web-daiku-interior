<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SDM (Sprint 10, §3.1): reprimands and warning letters (SP1–SP3).
 * Append-only: a mistake is corrected by a PEMBATALAN row whose
 * `voids_id` points at the cancelled record, never by edit/delete.
 * `valid_until` is set for SP rows (default 6 months) and drives the
 * escalation rule (decision #12, DisciplineService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disciplinary_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('type', 20); // App\Enums\DisciplinaryType
            $table->date('issued_on');
            $table->date('valid_until')->nullable();
            $table->text('description');
            $table->string('link', 500)->nullable();
            $table->foreignId('voids_id')->nullable()->unique()->constrained('disciplinary_records')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['employee_id', 'type', 'issued_on']);
            $table->index('issued_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_records');
    }
};
