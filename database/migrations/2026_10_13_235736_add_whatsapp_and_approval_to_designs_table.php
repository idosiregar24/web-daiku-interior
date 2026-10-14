<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 23 K18–K19b — a design's talk with the client runs in a
     * WhatsApp group: the system keeps only the group link (next to the
     * design links in `design_urls`), who last saved those links, and who
     * recorded "Desain Disetujui Klien" and when. The Sprint 22 columns
     * (`ready_for_client_at`, `ready_note`, `sent_to_client_at`,
     * `revision_count`) stay for old rows but are no longer written.
     */
    public function up(): void
    {
        Schema::table('designs', function (Blueprint $table) {
            $table->string('whatsapp_group_url', 500)->nullable()->after('design_urls');
            $table->foreignId('links_updated_by')->nullable()->after('whatsapp_group_url')->constrained('users')->nullOnDelete();
            $table->timestamp('links_updated_at')->nullable()->after('links_updated_by');
            $table->foreignId('client_acc_by')->nullable()->after('acc_date')->constrained('users')->nullOnDelete();
            $table->timestamp('client_acc_at')->nullable()->after('client_acc_by');
        });

        // Approvals recorded before this column existed: take who/when from
        // the audit trail (DesignService::markClientApproved() always wrote one).
        DB::table('audit_logs')
            ->where('action', 'design.client_approved')
            ->where('model_type', 'Design')
            ->orderBy('id')
            ->get(['model_id', 'user_id', 'created_at'])
            ->each(fn (object $log) => DB::table('designs')
                ->where('id', $log->model_id)
                ->whereNull('client_acc_by')
                ->update(['client_acc_by' => $log->user_id, 'client_acc_at' => $log->created_at]));
    }

    public function down(): void
    {
        Schema::table('designs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('links_updated_by');
            $table->dropConstrainedForeignId('client_acc_by');
            $table->dropColumn(['whatsapp_group_url', 'links_updated_at', 'client_acc_at']);
        });
    }
};
