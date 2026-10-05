<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 12 Sub 3 (decisions #6, #7, #11) — three kinds of quotation
     * (SURVEY / DESAIN / PROYEK), requested by Marketing, so a lead now
     * has several: the one-per-lead UNIQUE on `lead_id` goes (a plain index
     * stays for the FK). Existing quotations are PROYEK.
     *
     * Totals follow the Excel RAB: `items_total` (Σ item subtotals) →
     * `discount_amount` → `rounded_total` (Estimator's rounding, null =
     * no rounding). `total_amount` keeps meaning "what the client pays"
     * — every existing reader (CRM, dashboards, analytics) keeps working.
     *
     * `lead_survey_id` links a RAB Jasa Survey to its survey;
     * `parent_quotation_id` is reserved for addenda (Sub 12). The
     * quotation table never had a `design_id` (the plan's "design_id →
     * nullable" is moot): the design is reached through the lead.
     */
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->index('lead_id');
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->dropUnique(['lead_id']);

            $table->string('type', 20)->default('PROYEK')->after('lead_id');
            $table->foreignId('lead_survey_id')->nullable()->after('type')->constrained()->nullOnDelete();
            $table->foreignId('parent_quotation_id')->nullable()->after('lead_survey_id')->constrained('quotations')->nullOnDelete();
            $table->decimal('items_total', 15, 2)->default(0)->after('parent_quotation_id');
            $table->decimal('discount_amount', 15, 2)->default(0)->after('items_total');
            $table->decimal('rounded_total', 15, 2)->nullable()->after('discount_amount');
            $table->foreignId('requested_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->text('request_note')->nullable()->after('requested_by');

            $table->index(['lead_id', 'type']);
        });

        DB::table('quotations')->update(['items_total' => DB::raw('total_amount')]);
    }

    /** Only reversible while every lead still has at most one quotation. */
    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropIndex(['lead_id', 'type']);
            $table->dropConstrainedForeignId('lead_survey_id');
            $table->dropConstrainedForeignId('parent_quotation_id');
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn(['type', 'items_total', 'discount_amount', 'rounded_total', 'request_note']);
            $table->unique('lead_id');
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->dropIndex(['lead_id']);
        });
    }
};
