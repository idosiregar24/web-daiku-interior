<?php

namespace App\Console\Commands;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Services\WorkItemService;
use Illuminate\Console\Command;

/**
 * Sprint 29 Sub 08 (B2) — once, after deploying: the running projects opened
 * before Upah Tukang per item get their work items (no wage yet — the
 * Pengawas fills them; their progress stays actual activity until then).
 */
class BackfillWorkItems extends Command
{
    protected $signature = 'daiku:work-items';

    protected $description = 'Buat item kerja (Upah Tukang per item RAB) untuk proyek berjalan yang dibuka sebelum Sprint 29';

    public function handle(WorkItemService $service): int
    {
        $created = 0;

        Project::query()
            ->whereIn('status', [ProjectStatus::Active->value, ProjectStatus::OnHold->value])
            ->whereNotNull('quotation_id')
            ->each(function (Project $project) use ($service, &$created) {
                $created += $service->ensureForProject($project);
            });

        $this->info("{$created} item kerja dibuat.");

        return self::SUCCESS;
    }
}
