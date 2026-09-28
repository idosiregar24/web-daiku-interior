<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.7 "Hutang Supplier" — daiku_schema.sql `supplier_debts`, on
     * bigint PKs like the rest of the shipped tables. Append-only (PRD
     * §9.4): no soft deletes, corrections go through a new payment row.
     * `remaining` is a stored generated column, exactly as the schema file.
     */
    public function up(): void
    {
        Schema::create('supplier_debts', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_name', 100);
            $table->decimal('total_amount', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('remaining', 15, 2)->storedAs('total_amount - paid_amount');
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description')->nullable();
            $table->date('due_date')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('due_date');
            $table->index('supplier_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_debts');
    }
};
