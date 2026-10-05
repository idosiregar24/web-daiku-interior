<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 12 decisions #15–#17 — a design is born from an approved RAB
     * Jasa Desain (`quotation_id`; null = a pre-Sprint-12 design opened by
     * hand), assigned by a Kepala Desain once paid, and its client
     * revisions are counted. `pic_id` was already nullable — it stays
     * empty until the assignment.
     */
    public function up(): void
    {
        Schema::table('designs', function (Blueprint $table) {
            $table->foreignId('quotation_id')->nullable()->after('lead_id')->constrained()->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->after('pic_id')->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('assigned_by');
            $table->unsignedInteger('revision_count')->default(0)->after('acc_date');
            $table->timestamp('sent_to_client_at')->nullable()->after('revision_count');
        });
    }

    public function down(): void
    {
        Schema::table('designs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quotation_id');
            $table->dropConstrainedForeignId('assigned_by');
            $table->dropColumn(['assigned_at', 'revision_count', 'sent_to_client_at']);
        });
    }
};
