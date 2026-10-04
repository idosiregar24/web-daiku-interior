<?php

namespace Database\Factories;

use App\Models\KpiTemplate;
use App\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KpiTemplate> */
class KpiTemplateFactory extends Factory
{
    protected $model = KpiTemplate::class;

    public function definition(): array
    {
        return [
            'position_id' => Position::factory(),
            'is_active' => true,
            'created_by' => null,
        ];
    }
}
