<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 12 Sub 2 (decision #2) — follow-ups become numbered rows
     * FU-1, FU-2, … each with its own scheduled date and result note,
     * instead of the single `leads.follow_up_date`. A lead's "next
     * follow-up" is its earliest one not yet done.
     *
     * Backfill: every lead's `follow_up_date` becomes its FU-1 (still to
     * do), then the column is dropped. `down()` puts the earliest open
     * follow-up back into the column.
     */
    public function up(): void
    {
        Schema::create('lead_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->date('scheduled_date');
            $table->timestamp('done_at')->nullable();
            $table->text('result_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['lead_id', 'sequence']);
            $table->index(['scheduled_date', 'done_at']);
        });

        $now = now();
        foreach (DB::table('leads')->whereNotNull('follow_up_date')->get(['id', 'follow_up_date', 'created_by']) as $lead) {
            DB::table('lead_follow_ups')->insert([
                'lead_id' => $lead->id,
                'sequence' => 1,
                'scheduled_date' => $lead->follow_up_date,
                'created_by' => $lead->created_by,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('follow_up_date');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->date('follow_up_date')->nullable()->after('assigned_to');
        });

        $next = DB::table('lead_follow_ups')
            ->whereNull('done_at')
            ->select('lead_id', DB::raw('MIN(scheduled_date) as next_date'))
            ->groupBy('lead_id')
            ->get();

        foreach ($next as $row) {
            DB::table('leads')->where('id', $row->lead_id)->update(['follow_up_date' => $row->next_date]);
        }

        Schema::dropIfExists('lead_follow_ups');
    }
};
