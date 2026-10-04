<?php

namespace Database\Factories;

use App\Enums\MaterialRequestStatus;
use App\Enums\ProjectMaterialSource;
use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectMaterial>
 */
class ProjectMaterialFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'source' => ProjectMaterialSource::Gudang->value,
            'material_id' => Material::factory(),
            // The line's unit is always its catalog item's unit.
            'unit_id' => fn (array $attributes) => Material::find($attributes['material_id'])?->unit_id,
            'request_status' => MaterialRequestStatus::Disetujui->value,
            'qty_planned' => 20,
        ];
    }

    public function purchased(): static
    {
        return $this->state(fn () => ['source' => ProjectMaterialSource::Pembelian->value]);
    }

    /** A one-off item outside the catalog (normally born from an approved request, Sub 4). */
    public function custom(string $name = 'Kaca potong 8mm'): static
    {
        return $this->state(fn () => [
            'source' => ProjectMaterialSource::Custom->value,
            'material_id' => null,
            'custom_name' => $name,
            'unit_id' => Unit::factory(),
        ]);
    }
}
