<?php

namespace Database\Seeders;

use App\Models\MaterialCategory;
use App\Models\MaterialSynonym;
use Illuminate\Database\Seeder;

/**
 * Sprint 11 Sub 5 — default material categories and name synonyms.
 * Idempotent: existing rows keep whatever SUPERADMIN changed.
 */
class MaterialCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $order = 0;

        foreach (MaterialCategory::DEFAULTS as $name => $prefix) {
            MaterialCategory::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()
                || MaterialCategory::query()->where('code_prefix', $prefix)->exists()
                || MaterialCategory::create(['name' => $name, 'code_prefix' => $prefix, 'sort_order' => ++$order]);
        }

        foreach (MaterialSynonym::DEFAULTS as $term => $canonical) {
            if ($term !== $canonical) {
                MaterialSynonym::firstOrCreate(['term' => $term], ['canonical' => $canonical]);
            }
        }
    }
}
