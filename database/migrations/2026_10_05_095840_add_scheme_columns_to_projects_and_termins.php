<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 12 decisions #12, #19–#21 — a project opened by the CEO
     * carries its "RAB Fix" (`quotation_id`) and optional Asisten PM (D2);
     * its termins are copied from the approved payment scheme: the row
     * they came from, its trigger (DI_MUKA / TANGGAL / MILESTONE /
     * PROYEK_SELESAI) and milestone name, and the invoice Marketing issued
     * for them. Scheme percentages can be fractional (33,33 %), and a
     * milestone- or completion-triggered termin has no date yet — so
     * `percentage` becomes DECIMAL(5,2) and `scheduled_date` nullable.
     * Pre-Sprint-12 projects and termins keep these columns null.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('quotation_id')->nullable()->after('lead_id')->constrained();
            $table->foreignId('assistant_pm_id')->nullable()->after('pm_id')->constrained('users');
        });

        Schema::table('termins', function (Blueprint $table) {
            $table->decimal('percentage', 5, 2)->change();
            $table->date('scheduled_date')->nullable()->change();
            $table->foreignId('payment_term_id')->nullable()->after('milestone_id')->constrained('quotation_payment_terms')->nullOnDelete();
            $table->string('trigger', 20)->nullable()->after('payment_term_id');
            $table->string('milestone_name', 150)->nullable()->after('trigger');
            $table->foreignId('invoice_id')->nullable()->after('bank_account_id')->constrained()->nullOnDelete();
            $table->timestamp('invoice_reminded_at')->nullable()->after('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('termins', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropConstrainedForeignId('payment_term_id');
            $table->dropColumn(['trigger', 'milestone_name', 'invoice_reminded_at']);
            $table->date('scheduled_date')->nullable(false)->change();
            $table->unsignedTinyInteger('percentage')->change();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assistant_pm_id');
            $table->dropConstrainedForeignId('quotation_id');
        });
    }
};
