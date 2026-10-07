<?php

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;

class CitySeeder extends Seeder
{
    /**
     * Sprint 16 Sub 08 — the 12 kabupaten/kota of Riau plus the nearby big
     * cities clients come from (City::DEFAULTS). Only adds names that are
     * missing, so it is safe to re-run on deploy; the admin's own rows and
     * renames are never touched.
     */
    public function run(): void
    {
        foreach (City::DEFAULTS as $name => $province) {
            City::firstOrCreate(['name' => $name], ['province' => $province]);
        }
    }
}
