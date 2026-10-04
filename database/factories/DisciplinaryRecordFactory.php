<?php

namespace Database\Factories;

use App\Enums\DisciplinaryType;
use App\Models\DisciplinaryRecord;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Raw rows for tests that need history in place — production writes go
 * through DisciplineService (escalation rule, audit, notification).
 *
 * @extends Factory<DisciplinaryRecord>
 */
class DisciplinaryRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'type' => DisciplinaryType::TeguranLisan,
            'issued_on' => now()->subDays(10)->toDateString(),
            'valid_until' => null,
            'description' => fake()->sentence(),
            'link' => null,
            'voids_id' => null,
            'recorded_by' => User::factory(),
        ];
    }

    /** An SP of `$level` issued `$daysAgo` days ago, valid 6 months from then. */
    public function sp(int $level = 1, int $daysAgo = 10): static
    {
        return $this->state(fn () => [
            'type' => DisciplinaryType::from("SP{$level}"),
            'issued_on' => now()->subDays($daysAgo)->toDateString(),
            'valid_until' => now()->subDays($daysAgo)->addMonthsNoOverflow(6)->toDateString(),
        ]);
    }
}
