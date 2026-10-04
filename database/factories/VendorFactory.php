<?php

namespace Database\Factories;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vendor>
 */
class VendorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'contact' => fake()->numerify('08##########'),
            'address' => fake()->optional()->address(),
            'type' => Vendor::TYPE_MATERIAL,
            'bank_name' => fake()->randomElement(['BCA', 'Mandiri', 'BRI', null]),
            'bank_account_number' => fake()->optional()->numerify('##########'),
            'account_holder' => null,
            'is_active' => true,
            'created_by' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
