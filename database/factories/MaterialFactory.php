<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\Unit;
use App\Services\MaterialCatalogService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Material>
 */
class MaterialFactory extends Factory
{
    public function definition(): array
    {
        $cost = fake()->randomElement([45_000, 120_000, 185_000, 350_000]);

        return [
            'material_category_id' => MaterialCategory::factory(),
            'code' => fake()->unique()->bothify('TST-#####'),
            'name' => fake()->randomElement(['Plywood', 'HPL', 'MDF', 'Engsel', 'Rel Laci', 'Lem Kayu']).' '.fake()->unique()->numerify('###'),
            // Factories write the display name directly; the base name follows it.
            'base_name' => fn (array $attributes) => $attributes['name'],
            'unit_id' => Unit::factory(),
            'cost_price' => $cost,
            'sell_price' => $cost * 1.3,
            'stock' => 50,
            'min_stock' => 10,
        ];
    }

    /**
     * Give the item its match_key the way MaterialCatalogService would, so
     * factory items take part in the anti-duplicate checks (Sprint 11 §5.5).
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Material $material) {
            $key = app(MaterialCatalogService::class)->matchKey($material->only([
                'material_category_id', 'base_name', 'spec', 'brand', 'unit_id',
            ]));
            $taken = Material::query()->where('match_key', $key)->whereKeyNot($material->id)->exists();

            $material->forceFill($taken ? ['duplicate_key' => $key, 'possible_duplicate' => true] : ['match_key' => $key])->saveQuietly();
        });
    }
}
