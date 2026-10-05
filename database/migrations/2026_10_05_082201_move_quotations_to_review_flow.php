<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 12 decisions #7–#10 — approval order flips to PM (or Asisten
     * PM) first, then CEO for a project RAB:
     *
     * - SUBMITTED used to mean "waiting for the CEO"; it now means
     *   "waiting for PM" — kept as is. CEO_REVIEW ("CEO approved, waiting
     *   for PM") → SUBMITTED: the PM reviews it, then the CEO decides again.
     * - APPROVED (client accepted) → CLIENT_APPROVED, so it can't be
     *   confused with the new APPROVED_INTERNAL.
     *
     * "Sent to the client" used to be the PM's approval row; it is now
     * Marketing's own step, so the moment moves onto the quotation
     * (`first_sent_at` / `sent_at`, backfilled from those approval rows).
     * quotation_approvals history is left untouched (append-only).
     */
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->timestamp('first_sent_at')->nullable()->after('valid_until');
            $table->timestamp('sent_at')->nullable()->after('first_sent_at');
        });

        $sent = DB::table('quotation_approvals')
            ->where('approver_role', 'PM')
            ->where('status', 'APPROVED')
            ->groupBy('quotation_id')
            ->selectRaw('quotation_id, MIN(created_at) as first_sent_at, MAX(created_at) as sent_at')
            ->get();

        foreach ($sent as $row) {
            DB::table('quotations')->where('id', $row->quotation_id)->update([
                'first_sent_at' => $row->first_sent_at,
                'sent_at' => $row->sent_at,
            ]);
        }

        DB::table('quotations')->where('status', 'CEO_REVIEW')->update(['status' => 'SUBMITTED']);
        DB::table('quotations')->where('status', 'APPROVED')->update(['status' => 'CLIENT_APPROVED']);
    }

    /**
     * Best effort: the old machine had no APPROVED_INTERNAL / READY_TO_SEND /
     * CANCELLED — an internally approved RAB goes back to CEO_REVIEW (one
     * gate left), a waiting-for-CEO one to SUBMITTED, a cancelled one to
     * REJECTED (reserved, never acted on).
     */
    public function down(): void
    {
        DB::table('quotations')->where('status', 'CLIENT_APPROVED')->update(['status' => 'APPROVED']);
        DB::table('quotations')->where('status', 'WAITING_CEO')->update(['status' => 'SUBMITTED']);
        DB::table('quotations')->whereIn('status', ['APPROVED_INTERNAL', 'READY_TO_SEND'])->update(['status' => 'CEO_REVIEW']);
        DB::table('quotations')->where('status', 'CANCELLED')->update(['status' => 'REJECTED']);

        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn(['first_sent_at', 'sent_at']);
        });
    }
};
