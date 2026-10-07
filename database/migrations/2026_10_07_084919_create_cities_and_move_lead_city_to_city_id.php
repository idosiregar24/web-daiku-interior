<?php

use App\Models\City;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 16 Sub 08 — Master Kota. `leads.city` (free text, so "Pekanbaru",
 * "pekanbaru", "PKU" were three cities) becomes `leads.city_id`. Every old
 * value is mapped through City::canonicalName(); a name the list doesn't
 * know becomes a new master row (never lost) for the admin to tidy up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('province', 100)->nullable();
            $table->timestamps();
        });

        Schema::table('leads', function (Blueprint $table) {
            // restrict: a city in use is renamed, never deleted (CityController::destroy()).
            $table->foreignId('city_id')->nullable()->after('lead_category_id')->constrained()->restrictOnDelete();
        });

        $values = DB::table('leads')->whereNotNull('city')->where('city', '!=', '')->distinct()->pluck('city');

        foreach ($values as $value) {
            $name = City::canonicalName($value);
            $cityId = DB::table('cities')->where('name', $name)->value('id')
                ?? DB::table('cities')->insertGetId([
                    'name' => $name,
                    'province' => City::DEFAULTS[$name] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('leads')->where('city', $value)->update(['city_id' => $cityId]);
        }

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('city');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('city')->nullable()->after('service');
        });

        DB::table('leads')->whereNotNull('city_id')->update([
            'city' => DB::raw('(SELECT name FROM cities WHERE cities.id = leads.city_id)'),
        ]);

        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('city_id');
        });

        Schema::dropIfExists('cities');
    }
};
