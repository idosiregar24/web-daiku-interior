<?php

namespace Database\Factories;

use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectMaterial;
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
            'material_id' => Material::factory(),
            'qty_planned' => 20,
        ];
    }
}
