<?php

namespace Database\Factories;

use App\Enums\QaStatus;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\QaForm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QaForm>
 */
class QaFormFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'milestone_id' => Milestone::factory(),
            'reviewer_id' => null,
            'status' => QaStatus::Pending->value,
            'checklist_data' => [
                ['label' => 'Hasil pekerjaan sesuai spesifikasi/desain', 'passed' => false, 'note' => null],
                ['label' => 'Kerapian dan kebersihan area kerja', 'passed' => false, 'note' => null],
            ],
            'rejection_count' => 0,
            'notes' => null,
            'reviewed_at' => null,
        ];
    }
}
