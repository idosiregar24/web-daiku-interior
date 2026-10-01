<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §4.7 "Gaji Karyawan Tetap" — permanent (monthly-salaried) staff.
 * Not in daiku_schema.sql, designed in Sprint 9 (decision #7): a list
 * separate from `users` because not every employee has a system account;
 * `user_id` optionally links one (at most one employee per account).
 *
 * Employees are deactivated (`is_active`), never deleted — their salary
 * history (`salary_payments`, restrictOnDelete) must survive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('position', 100);
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->decimal('base_salary', 15, 2);
            $table->string('bank_name', 50)->nullable();
            $table->string('account_no', 50)->nullable();
            $table->date('join_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
