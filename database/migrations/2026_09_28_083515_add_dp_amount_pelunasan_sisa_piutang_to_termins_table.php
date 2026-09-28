<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * daiku_schema.sql `termins`: DP + pelunasan partial payments with a
     * DB-generated `sisa_piutang` (PRD §4.7 "sisa piutang otomatis
     * terhitung"). MySQL gets the schema's STORED generated column;
     * SQLite (the in-memory test connection) cannot ALTER TABLE ADD a
     * STORED generated column, so it gets the VIRTUAL equivalent — same
     * expression, same reads, only the on-disk storage differs.
     */
    public function up(): void
    {
        Schema::table('termins', function (Blueprint $table) {
            $table->decimal('dp_amount', 15, 2)->default(0)->after('amount');
            $table->decimal('pelunasan', 15, 2)->default(0)->after('dp_amount');
        });

        Schema::table('termins', function (Blueprint $table) {
            $column = $table->decimal('sisa_piutang', 15, 2);
            $expression = 'amount - dp_amount - pelunasan';

            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $column->virtualAs($expression);
            } else {
                $column->storedAs($expression)->after('pelunasan');
            }
        });

        // Termins paid through the old all-or-nothing markPaid() were paid
        // in full — record that as pelunasan so sisa_piutang reads 0, not
        // the whole amount. down() drops the columns, so this reverses too.
        DB::table('termins')
            ->where('status', 'PAID')
            ->where('dp_amount', 0)
            ->where('pelunasan', 0)
            ->update(['pelunasan' => DB::raw('amount')]);
    }

    /** The generated column references the other two, so it must be dropped first. */
    public function down(): void
    {
        Schema::table('termins', function (Blueprint $table) {
            $table->dropColumn('sisa_piutang');
        });

        Schema::table('termins', function (Blueprint $table) {
            $table->dropColumn(['dp_amount', 'pelunasan']);
        });
    }
};
