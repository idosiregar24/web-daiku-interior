<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master Data — Cabang. PRD §11.3 anticipates multi-branch expansion
     * (a future `branch_id` on main tables) but doesn't define this table;
     * added on request as a SuperAdmin-managed reference table. Not yet
     * referenced by any other table — wiring `branch_id` FKs onto
     * leads/projects/etc. is a separate, larger change, out of scope here.
     */
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('address')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
