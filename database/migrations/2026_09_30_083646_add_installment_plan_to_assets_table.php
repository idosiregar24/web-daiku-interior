<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §4.7 "Aset & Cicilan" — the installment columns daiku_schema.sql
 * puts on `assets` (`has_installment`, `total_install`, `paid_install`),
 * plus two deviations (Sprint 9, SA-6):
 *
 * - `installment_amount`: expected amount per payment — prefills the
 *   Finance payment dialog, like `staff_loans.installment_amount`. The
 *   schema has no other way to know it.
 * - `installment_due_day` (1–28): day of the month the installment is due,
 *   so the Cicilan Aset page can flag "jatuh tempo bulan ini". Capped at
 *   28 so every month has that day.
 *
 * `paid_install` mirrors SUM(asset_installment_payments.amount); only
 * AssetInstallmentService writes it, under a row lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->boolean('has_installment')->default(false)->after('location');
            $table->decimal('total_install', 15, 2)->nullable()->after('has_installment');
            $table->decimal('paid_install', 15, 2)->default(0)->after('total_install');
            $table->decimal('installment_amount', 15, 2)->nullable()->after('paid_install');
            $table->unsignedTinyInteger('installment_due_day')->nullable()->after('installment_amount');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['has_installment', 'total_install', 'paid_install', 'installment_amount', 'installment_due_day']);
        });
    }
};
