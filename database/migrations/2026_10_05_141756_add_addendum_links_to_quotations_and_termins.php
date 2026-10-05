<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 12 decision #29 / D7 — RAB Tambahan (addendum): a PROYEK
     * quotation with `parent_quotation_id` = the RAB Fix and `project_id`
     * = the running project. Each termin remembers which RAB it bills
     * (`termins.quotation_id`), so an addendum's termins (invoice type
     * TAMBAHAN) stay apart from the RAB Fix scheme. Scheme termins of Sub 7
     * are backfilled from their payment term.
     */
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('parent_quotation_id')->constrained()->nullOnDelete();
        });

        Schema::table('termins', function (Blueprint $table) {
            $table->foreignId('quotation_id')->nullable()->after('payment_term_id')->constrained()->nullOnDelete();
        });

        DB::table('termins')
            ->whereNotNull('payment_term_id')
            ->orderBy('id')
            ->each(function (object $termin) {
                $quotationId = DB::table('quotation_payment_terms')->where('id', $termin->payment_term_id)->value('quotation_id');
                DB::table('termins')->where('id', $termin->id)->update(['quotation_id' => $quotationId]);
            });
    }

    public function down(): void
    {
        Schema::table('termins', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quotation_id');
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
