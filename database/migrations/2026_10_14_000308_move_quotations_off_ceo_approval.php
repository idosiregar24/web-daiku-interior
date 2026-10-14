<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Audit action of every row this migration moves (the Audit Log page filters on the `quotation.` prefix). */
    private const ACTION = 'quotation.ceo_stage_removed';

    private const LEGACY_STATUSES = ['WAITING_CEO', 'APPROVED_INTERNAL'];

    /**
     * Sprint 23 K8 — the CEO no longer approves a RAB Proyek and the
     * Estimator no longer hands the RAB to Marketing: a PM / Asisten PM
     * approval goes straight to READY_TO_SEND. RAB still waiting for the
     * CEO (WAITING_CEO) or for "Kirim ke Marketing" (APPROVED_INTERNAL)
     * move there now — the PM already approved them. One audit row each
     * ("Sistem", no user), written with the query builder so the step
     * doesn't depend on today's models. Works on MySQL and SQLite.
     */
    public function up(): void
    {
        $now = now();

        DB::table('quotations')
            ->whereIn('status', self::LEGACY_STATUSES)
            ->orderBy('id')
            ->get(['id', 'status', 'version', 'total_amount'])
            ->each(function (object $quotation) use ($now) {
                DB::table('quotations')->where('id', $quotation->id)->update(['status' => 'READY_TO_SEND', 'updated_at' => $now]);

                DB::table('audit_logs')->insert([
                    'user_id' => null,
                    'action' => self::ACTION,
                    'model_type' => 'Quotation',
                    'model_id' => $quotation->id,
                    'old_values' => json_encode(['status' => $quotation->status, 'version' => (int) $quotation->version]),
                    'new_values' => json_encode([
                        'status' => 'READY_TO_SEND',
                        'version' => (int) $quotation->version,
                        'total_amount' => $quotation->total_amount,
                        'note' => 'Sprint 23: persetujuan CEO & "Kirim ke Marketing" dihapus — RAB yang sudah di-ACC PM langsung ke Marketing.',
                    ]),
                    'ip_address' => null,
                    'created_at' => $now,
                ]);
            });
    }

    /**
     * Puts back the rows this migration moved (read from its own audit
     * rows) that are still READY_TO_SEND. The audit rows stay — the
     * audit trail is append-only.
     */
    public function down(): void
    {
        DB::table('audit_logs')
            ->where('action', self::ACTION)
            ->where('model_type', 'Quotation')
            ->orderBy('id')
            ->get(['model_id', 'old_values'])
            ->each(function (object $log) {
                $old = json_decode((string) $log->old_values, true);

                if (! in_array($old['status'] ?? null, self::LEGACY_STATUSES, true)) {
                    return;
                }

                DB::table('quotations')
                    ->where('id', $log->model_id)
                    ->where('status', 'READY_TO_SEND')
                    ->update(['status' => $old['status']]);
            });
    }
};
