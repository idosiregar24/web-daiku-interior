<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 21 — log in with a username. An account has a username, an email,
 * or both (ValidatesUserIdentity), so `email` becomes nullable; its UNIQUE
 * index stays (MySQL allows many NULLs). Existing users get no username —
 * they keep logging in with their email and pick one in Profil Saya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->nullable()->unique()->after('name');
            // Last time the user changed it themselves — K4 "sekali per 40 hari".
            $table->timestamp('username_changed_at')->nullable()->after('username');
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        // An account without an email can't go back to NOT NULL as is.
        DB::table('users')->whereNull('email')->pluck('id')->each(
            fn (int $id) => DB::table('users')->where('id', $id)->update(['email' => "{$id}@no-email.invalid"]),
        );

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'username_changed_at']);
        });
    }
};
