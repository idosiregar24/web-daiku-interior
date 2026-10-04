<?php

namespace Database\Factories;

use App\Models\SupplierDebt;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierDebt>
 */
class SupplierDebtFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'total_amount' => fake()->randomFloat(2, 1_000_000, 50_000_000),
            'paid_amount' => 0,
            'project_id' => null,
            'description' => fake()->optional()->sentence(),
            'due_date' => fake()->dateTimeBetween('+1 week', '+2 months')->format('Y-m-d'),
            'created_by' => User::factory(),
        ];
    }

    public function overdue(): static
    {
        return $this->state(fn () => ['due_date' => now()->subDays(5)->toDateString()]);
    }

    public function paidOff(): static
    {
        return $this->state(fn (array $attributes) => ['paid_amount' => $attributes['total_amount']]);
    }
}
