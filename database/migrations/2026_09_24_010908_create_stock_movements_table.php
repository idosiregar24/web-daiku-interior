<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §4.8 "Manajemen Stok: Input penerimaan barang (stok bertambah) /
     * Input pemakaian barang per proyek (stok berkurang)". Not in
     * daiku_schema.sql, which only has the running `materials.stock`
     * total — but without a ledger there'd be no record of *who*
     * received/used *what* and *when*, only a number that changed. This
     * append-only log is that record (no updated_at, no update/delete
     * routes — same stance as the other audit-style tables, PRD §9.4).
     *
     * `project_id` is required for OUT (PRD "Pemakaian material wajib
     * terhubung ke proyek — tidak ada pemakaian floating"), null for IN.
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 10); // IN / OUT — App\Enums\StockMovementType
            $table->unsignedInteger('qty');
            $table->unsignedInteger('stock_after');
            $table->date('movement_date');
            $table->string('note')->nullable();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamp('created_at')->nullable();

            $table->index(['material_id', 'movement_date']);
            $table->index(['project_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
