<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 11 Sub 2 — Master Vendor (Sprint 12 decision #32, moved here
     * because project material purchases need it). Reused by supplier
     * debts now and budget realisation later. CRUD by CEO + SUPERADMIN;
     * a vendor already referenced is deactivated, never deleted.
     * `created_by` is nullable only for rows the supplier-debt backfill
     * creates (no acting user inside a migration).
     */
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('contact', 100)->nullable();
            $table->text('address')->nullable();
            $table->string('type', 20)->default('MATERIAL'); // MATERIAL | JASA — App\Models\Vendor::TYPES
            $table->string('bank_name', 50)->nullable();
            $table->string('bank_account_number', 50)->nullable();
            $table->string('account_holder', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
