<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 24 D1/D11 — the execution phase inside ACTIVE (GAMBAR_KERJA →
     * TM → PERSIAPAN → PRODUKSI → PEMASANGAN). The column default is
     * PRODUKSI so every project opened before this sprint keeps working
     * unlocked (D1); "Buka Proyek" sets GAMBAR_KERJA explicitly
     * (ProjectService::openFromQuotation()). Plus when production started,
     * when the PM was told about 70 % (once) and who approved leaving for
     * the site.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('phase', 20)->default('PRODUKSI')->after('status')->index();
            $table->timestamp('phase_changed_at')->nullable()->after('phase');
            $table->timestamp('production_started_at')->nullable()->after('phase_changed_at');
            $table->timestamp('progress_70_notified_at')->nullable()->after('production_started_at');
            $table->foreignId('departure_approved_by')->nullable()->after('progress_70_notified_at')->constrained('users')->nullOnDelete();
            $table->timestamp('departure_approved_at')->nullable()->after('departure_approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('departure_approved_by');
            $table->dropIndex(['phase']);
            $table->dropColumn(['phase', 'phase_changed_at', 'production_started_at', 'progress_70_notified_at', 'departure_approved_at']);
        });
    }
};
