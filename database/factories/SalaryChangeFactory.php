<?php

namespace Database\Factories;

use App\Enums\SalaryChangeStatus;
use App\Models\Employee;
use App\Models\SalaryChange;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Raw PENDING rows for tests — production writes go through
 * SalaryChangeService (one pending per employee, audit, notifications).
 *
 * @extends Factory<SalaryChange>
 */
class SalaryChangeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'old_salary' => 4_000_000,
            'new_salary' => 4_500_000,
            'effective_date' => now()->toDateString(),
            'reason' => fake()->sentence(),
            'status' => SalaryChangeStatus::Pending,
            'reject_note' => null,
            'performance_review_id' => null,
            'requested_by' => User::factory(),
            'decided_by' => null,
            'decided_at' => null,
        ];
    }
}
