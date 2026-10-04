<?php

namespace Database\Factories;

use App\Enums\KpiDirection;
use App\Enums\KpiIndicatorSource;
use App\Models\KpiIndicator;
use App\Models\KpiTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KpiIndicator> A MANUAL indicator by default; `auto()` for an AUTO one. */
class KpiIndicatorFactory extends Factory
{
    protected $model = KpiIndicator::class;

    public function definition(): array
    {
        return [
            'kpi_template_id' => KpiTemplate::factory(),
            'name' => 'Indikator '.fake()->unique()->numberBetween(1, 99999),
            'source' => KpiIndicatorSource::Manual->value,
            'metric_key' => null,
            'target' => 100,
            'weight' => 100,
            'direction' => KpiDirection::HigherBetter->value,
            'sort_order' => 0,
        ];
    }

    public function auto(string $metricKey, KpiDirection $direction = KpiDirection::HigherBetter): static
    {
        return $this->state(fn () => [
            'source' => KpiIndicatorSource::Auto->value,
            'metric_key' => $metricKey,
            'direction' => $direction->value,
        ]);
    }
}
