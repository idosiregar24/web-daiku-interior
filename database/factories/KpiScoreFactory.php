<?php

namespace Database\Factories;

use App\Enums\KpiDirection;
use App\Enums\KpiIndicatorSource;
use App\Models\Employee;
use App\Models\KpiPeriod;
use App\Models\KpiScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KpiScore> A scored MANUAL row with no backing indicator. */
class KpiScoreFactory extends Factory
{
    protected $model = KpiScore::class;

    public function definition(): array
    {
        return [
            'kpi_period_id' => KpiPeriod::factory(),
            'employee_id' => Employee::factory(),
            'kpi_indicator_id' => null,
            'indicator_name' => 'Indikator '.fake()->numberBetween(1, 999),
            'source' => KpiIndicatorSource::Manual->value,
            'metric_key' => null,
            'target' => 100,
            'weight' => 100,
            'direction' => KpiDirection::HigherBetter->value,
            'actual' => 80,
            'score' => 80,
            'weighted_score' => 80,
            'input_by' => null,
        ];
    }
}
