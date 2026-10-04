<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 11 Sub 2 — `supplier_debts.supplier_name` (free text) becomes
     * `vendor_id` into Master Vendor. Every distinct trimmed name gets a
     * vendor (case-insensitive: "Ideal" and "ideal " are one vendor), the
     * FK is backfilled and the text column dropped.
     *
     * `vendor_id` stays nullable at the database level — SQLite (tests)
     * rebuilds the whole table to tighten a column, which this table's
     * generated `remaining` column doesn't survive. StoreSupplierDebtRequest
     * requires it, and every pre-existing row is backfilled here.
     *
     * Query builder only, so it runs the same on MySQL and SQLite.
     */
    public function up(): void
    {
        Schema::table('supplier_debts', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->after('id')->constrained('vendors')->restrictOnDelete();
        });

        // Oldest spelling first, so it's the one that names the vendor.
        $names = DB::table('supplier_debts')
            ->select('supplier_name')
            ->groupBy('supplier_name')
            ->orderByRaw('MIN(id)')
            ->pluck('supplier_name');

        foreach ($names as $name) {
            DB::table('supplier_debts')
                ->where('supplier_name', $name)
                ->update(['vendor_id' => $this->vendorIdFor((string) $name)]);
        }

        Schema::table('supplier_debts', function (Blueprint $table) {
            $table->dropIndex(['supplier_name']);
            $table->dropColumn('supplier_name');
        });
    }

    /** Restores the supplier name text from each debt's vendor; the vendor rows themselves stay. */
    public function down(): void
    {
        Schema::table('supplier_debts', function (Blueprint $table) {
            $table->string('supplier_name', 100)->default('')->after('id');
            $table->index('supplier_name');
        });

        foreach (DB::table('vendors')->get(['id', 'name']) as $vendor) {
            DB::table('supplier_debts')->where('vendor_id', $vendor->id)->update(['supplier_name' => $vendor->name]);
        }

        Schema::table('supplier_debts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_id');
        });
    }

    private function vendorIdFor(string $name): int
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '') ?: 'Supplier tanpa nama';

        $id = DB::table('vendors')->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->value('id');

        return (int) ($id ?? DB::table('vendors')->insertGetId([
            'name' => mb_substr($name, 0, 100),
            'type' => 'MATERIAL',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }
};
