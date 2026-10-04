<?php

namespace Database\Factories;

use App\Models\MaterialCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaterialCategory>
 */
class MaterialCategoryFactory extends Factory
{
    public function definition(): array
    {
        $prefix = strtoupper(fake()->unique()->lexify('???'));

        return [
            'name' => 'Kategori '.$prefix,
            'code_prefix' => $prefix,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
