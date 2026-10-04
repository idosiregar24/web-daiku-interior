<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Sprint 11 Sub 1 — the common units (Unit::DEFAULTS). Idempotent: an
 * existing code keeps whatever name/active state SUPERADMIN gave it.
 */
class UnitSeeder extends Seeder
{
    public function run(): void
    {
        $order = 0;

        foreach (Unit::DEFAULTS as $code => $name) {
            Unit::firstOrCreate(['code' => $code], ['name' => $name, 'sort_order' => ++$order]);
        }
    }
}
