<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SDM (Sprint 10, §3.2): when an APPROVED salary change actually reached
 * `employees.base_salary`. Set on approval when the effective date has
 * already arrived, otherwise by SalaryChangeService::applyDue() (daily job)
 * on the effective date. Null + APPROVED = waiting to be applied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_changes', function (Blueprint $table) {
            $table->timestamp('applied_at')->nullable()->after('decided_at');
            $table->index(['status', 'applied_at', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::table('salary_changes', function (Blueprint $table) {
            $table->dropIndex(['status', 'applied_at', 'effective_date']);
            $table->dropColumn('applied_at');
        });
    }
};
