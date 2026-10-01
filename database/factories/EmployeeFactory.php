<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->firstName(),
            'position' => fake()->randomElement(['Admin', 'Marketing', 'Desainer', 'Estimator', 'Drafter', 'Staf Gudang']),
            'user_id' => null,
            'base_salary' => fake()->randomElement([3_500_000, 4_000_000, 4_500_000, 5_000_000, 6_000_000]),
            'bank_name' => 'BCA',
            'account_no' => fake()->numerify('##########'),
            'join_date' => fake()->dateTimeBetween('-4 years', '-2 months')->format('Y-m-d'),
            'is_active' => true,
            'notes' => null,
            'created_by' => User::factory(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
