<?php

namespace Database\Factories;

use App\Enums\KpiPeriodStatus;
use App\Models\KpiPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KpiPeriod> */
class KpiPeriodFactory extends Factory
{
    protected $model = KpiPeriod::class;

    public function definition(): array
    {
        return [
            'period' => now()->subMonthsNoOverflow(fake()->unique()->numberBetween(1, 120))->format('Y-m'),
            'status' => KpiPeriodStatus::Open->value,
            'closed_by' => null,
            'closed_at' => null,
        ];
    }
}
