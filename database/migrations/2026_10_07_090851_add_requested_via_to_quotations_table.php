<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 17 Sub 04 — where a RAB request came from. `DESIGN_ACC` = the
     * RAB Proyek DesignService::markClientApproved() asked for on its own
     * when the client approved the design; null = asked for by a person
     * (Marketing's "Buat RAB", a PM's RAB Tambahan). Lets the lead and
     * design pages say "already requested automatically" without matching
     * the request note's text.
     */
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->string('requested_via', 20)->nullable()->after('requested_by');
        });

        // Requests made before this column existed carry the service's fixed note.
        DB::table('quotations')
            ->where('type', 'PROYEK')
            ->whereNull('parent_quotation_id')
            ->where('request_note', 'like', 'Desain disetujui klien — susun RAB Proyek dari desain ini%')
            ->update(['requested_via' => 'DESIGN_ACC']);
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn('requested_via');
        });
    }
};
