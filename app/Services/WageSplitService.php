<?php

namespace App\Services;

use App\Enums\SupervisorPaymentStatus;
use App\Models\CareerLevel;
use App\Models\Project;
use App\Models\ProjectWageSplit;
use App\Models\ProjectWorkItem;
use App\Models\SupervisorPayment;
use App\Models\User;
use App\Models\WorkTeam;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 32 — "Pembagian Upah per Orang" (File D, `Upah Tim Interior
 * DAIKU.xlsx`): a help for a Kepala Tim who can't do it himself. The
 * Pengawas keeps team templates (members + position + weight), picks one per
 * Kepala Tim on a project, and every DP / pencairan of the Upah Tukang shows
 * each person's part:
 *
 *   % orang      = bobot ÷ Σ bobot tim × 100
 *   bagian orang = bagian tim (the payment's breakdown per Kepala Tim) × % orang
 *
 * in whole rupiah, the rounding rest to the largest remainders, so the parts
 * add up to the team's amount exactly. The "Perusahaan" row is the company's
 * share. Nothing here moves money — Finance still pays the Pengawas, who
 * hands it to the Kepala Tim (Sprint 29 Sub 08b decision).
 */
class WageSplitService
{
    public function __construct(private AuditLogService $auditLogService) {}

    /** @return Collection<int, CareerLevel> */
    public function levels(): Collection
    {
        return CareerLevel::query()->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * The positions for a page (weight as a number).
     *
     * @return list<array{id: int, name: string, weight: float, is_company: bool}>
     */
    public function levelOptions(): array
    {
        return $this->levels()->map(fn (CareerLevel $level) => [
            'id' => $level->id,
            'name' => $level->name,
            'weight' => (float) $level->weight,
            'is_company' => $level->is_company,
        ])->all();
    }

    /**
     * The team templates for a page.
     *
     * @return list<array{id: int, name: string, leader_name: ?string, members: list<array<string, mixed>>}>
     */
    public function templateOptions(): array
    {
        return $this->templates()->map(fn (WorkTeam $team) => [
            'id' => $team->id,
            'name' => $team->name,
            'leader_name' => $team->leader_name,
            'members' => $this->withPercents($team->members),
        ])->all();
    }

    /**
     * CEO — the default weights of the positions (File D "Master Persentasi").
     *
     * @param  list<array{id: int|string, name: string, weight: numeric-string|int|float}>  $rows
     */
    public function saveLevels(array $rows, User $actor): void
    {
        DB::transaction(function () use ($rows, $actor) {
            foreach ($rows as $row) {
                $level = CareerLevel::lockForUpdate()->findOrFail($row['id']);
                $before = $level->only(['name', 'weight']);
                $level->fill(['name' => trim($row['name']), 'weight' => round((float) $row['weight'], 2)]);

                if ($level->isDirty()) {
                    $level->save();
                    $this->auditLogService->record('wage_split.level_updated', $level, $before, $level->only(['name', 'weight']), $actor);
                }
            }
        });
    }

    /** @return Collection<int, WorkTeam> */
    public function templates(): Collection
    {
        return WorkTeam::query()->orderBy('name')->get();
    }

    /**
     * Create or update a "Tim Tukang" template.
     *
     * @param  array{name: string, leader_name?: ?string, members: list<array<string, mixed>>}  $data
     */
    public function saveTemplate(?WorkTeam $team, array $data, User $actor): WorkTeam
    {
        $members = $this->cleanMembers($data['members']);
        $attributes = [
            'name' => trim($data['name']),
            'leader_name' => filled($data['leader_name'] ?? null) ? trim($data['leader_name']) : null,
            'members' => $members,
            'updated_by' => $actor->id,
        ];

        $team = $team
            ? tap($team)->update($attributes)
            : WorkTeam::create([...$attributes, 'created_by' => $actor->id]);

        $this->auditLogService->record('wage_split.template_saved', $team, null, ['name' => $team->name, 'members' => $members], $actor);

        return $team;
    }

    public function deleteTemplate(WorkTeam $team, User $actor): void
    {
        $this->auditLogService->record('wage_split.template_deleted', $team, ['name' => $team->name, 'members' => $team->members], null, $actor);
        $team->delete();
    }

    /**
     * The Kepala Tim labels of a project — the same labels the payments'
     * breakdown uses (WorkItemService::figures()).
     *
     * @return list<string>
     */
    public function teamLabels(Project $project): array
    {
        return ProjectWorkItem::query()
            ->where('project_id', $project->id)
            ->where('unit_wage', '>', 0)
            ->with('teamLead:id,name')
            ->get()
            ->map(fn (ProjectWorkItem $item) => $item->teamLeadLabel() ?? WorkItemService::NO_TEAM_LEAD)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * The Pengawas / Admin Proyek sets one Kepala Tim's people on a project —
     * typed, or copied from a template (`work_team_id`). An empty member list
     * removes the split.
     *
     * @param  array{team_label: string, work_team_id?: int|string|null, members?: ?list<array<string, mixed>>}  $data
     */
    public function saveProjectSplit(Project $project, array $data, User $actor): ?ProjectWageSplit
    {
        $label = $data['team_label'];

        if (! in_array($label, $this->teamLabels($project), true)) {
            throw ValidationException::withMessages(['team_label' => 'Kepala Tim ini tidak ada di item berupah proyek ini.']);
        }

        $template = filled($data['work_team_id'] ?? null) ? WorkTeam::findOrFail($data['work_team_id']) : null;
        $members = $data['members'] ?? null;

        if ($members === null && $template) {
            $members = $template->members;
        }

        $split = ProjectWageSplit::where('project_id', $project->id)->where('team_label', $label)->first();

        if (! $members) {
            $split?->delete();
            $this->auditLogService->record('wage_split.project_cleared', $project, ['team_label' => $label], null, $actor);

            return null;
        }

        $members = $this->cleanMembers($members);
        $split = ProjectWageSplit::updateOrCreate(
            ['project_id' => $project->id, 'team_label' => $label],
            ['work_team_id' => $template?->id ?? $split?->work_team_id, 'members' => $members, 'updated_by' => $actor->id],
        );

        $this->auditLogService->record('wage_split.project_saved', $project, null, ['team_label' => $label, 'template' => $template?->name, 'members' => $members], $actor);

        return $split;
    }

    /**
     * The project's "Pembagian Upah" view: per Kepala Tim its people (or
     * none yet), and per DP / pencairan (not rejected) each person's part.
     *
     * @return array<string, mixed>
     */
    public function overview(Project $project): array
    {
        $splits = ProjectWageSplit::where('project_id', $project->id)->with('workTeam:id,name')->get()->keyBy('team_label');

        return [
            'teams' => collect($this->teamLabels($project))->map(fn (string $label) => [
                'label' => $label,
                'template' => $splits->get($label)?->workTeam?->name,
                'members' => $splits->has($label) ? $this->withPercents($splits->get($label)->members) : null,
            ])->all(),
            'slips' => SupervisorPayment::query()
                ->where('project_id', $project->id)
                ->where('status', '!=', SupervisorPaymentStatus::Ditolak->value)
                ->with('weeklyReport:id,week_number,sequence')
                ->orderByDesc('id')
                ->get()
                ->map(fn (SupervisorPayment $payment) => [
                    'id' => $payment->id,
                    // Inside the project already — the label without "· Proyek …".
                    'label' => $payment->setRelation('project', null)->label,
                    'kind' => $payment->kind->value,
                    'status' => $payment->status->value,
                    'amount' => (float) $payment->amount,
                    'date' => ($payment->paid_at ?? $payment->decided_at ?? $payment->requested_at)?->toIso8601String(),
                    'teams' => collect($payment->breakdown ?? [])->map(fn (array $share) => [
                        'label' => $share['label'],
                        'amount' => (float) $share['amount'],
                        'rows' => $splits->has($share['label']) ? $this->split((float) $share['amount'], $splits->get($share['label'])->members) : null,
                    ])->values()->all(),
                ])->all(),
        ];
    }

    /**
     * One team's amount per person — whole rupiah, the rest to the largest
     * remainders (the parts add up exactly).
     *
     * @param  list<array{name: ?string, level: ?string, weight: float|int|string, company: bool}>  $members
     * @return list<array{name: ?string, level: ?string, weight: float, percent: float, amount: float, company: bool}>
     */
    public function split(float $amount, array $members): array
    {
        $rows = $this->withPercents($members);
        $total = array_sum(array_map(fn (array $row) => $row['weight'], $rows));
        $rupiah = (int) round($amount);

        if ($total <= 0 || $rupiah <= 0) {
            return array_map(fn (array $row) => [...$row, 'amount' => 0.0], $rows);
        }

        $raw = array_map(fn (array $row) => $rupiah * $row['weight'] / $total, $rows);
        $parts = array_map(fn (float $value) => (int) floor($value), $raw);
        $order = array_keys($raw);
        usort($order, fn (int $a, int $b) => ($raw[$b] - floor($raw[$b])) <=> ($raw[$a] - floor($raw[$a])) ?: $a <=> $b);

        foreach (array_slice($order, 0, $rupiah - array_sum($parts)) as $index) {
            $parts[$index]++;
        }

        return array_map(fn (array $row, int $part) => [...$row, 'amount' => (float) $part], $rows, $parts);
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @return list<array{name: ?string, level: ?string, weight: float, percent: float, company: bool}>
     */
    private function withPercents(array $members): array
    {
        $total = array_sum(array_map(fn (array $row) => (float) $row['weight'], $members));

        return array_values(array_map(fn (array $row) => [
            'name' => $row['name'] ?? null,
            'level' => $row['level'] ?? null,
            'weight' => (float) $row['weight'],
            'percent' => $total > 0 ? round((float) $row['weight'] / $total * 100, 2) : 0.0,
            'company' => (bool) ($row['company'] ?? false),
        ], $members));
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @return list<array{name: ?string, level: ?string, weight: float, company: bool}>
     */
    private function cleanMembers(array $members): array
    {
        $clean = array_values(array_map(fn (array $row) => [
            'name' => filled($row['name'] ?? null) ? trim($row['name']) : null,
            'level' => filled($row['level'] ?? null) ? trim($row['level']) : null,
            'weight' => round((float) ($row['weight'] ?? 0), 2),
            'company' => (bool) ($row['company'] ?? false),
        ], $members));

        if ($clean === [] || collect($clean)->every(fn (array $row) => $row['company'])) {
            throw ValidationException::withMessages(['members' => 'Isi minimal satu anggota tim.']);
        }

        foreach ($clean as $index => $row) {
            if ($row['weight'] <= 0) {
                throw ValidationException::withMessages(["members.{$index}.weight" => 'Bobot harus lebih dari 0.']);
            }
            if (! $row['company'] && $row['name'] === null && $row['level'] === null) {
                throw ValidationException::withMessages(["members.{$index}.name" => 'Isi nama atau tingkatan anggota.']);
            }
        }

        return $clean;
    }
}
