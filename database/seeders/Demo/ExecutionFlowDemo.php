<?php

namespace Database\Seeders\Demo;

use App\Enums\ProjectPhase;
use App\Enums\TaskStatus;
use App\Enums\WorkingDrawingStatus;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\Unit;
use App\Models\User;
use App\Services\MaterialCatalogService;
use App\Services\MilestoneService;
use App\Services\ProjectMaterialService;
use App\Services\SupervisorPaymentService;
use App\Services\TaskService;
use App\Services\TechnicalMeetingService;
use App\Services\WorkingDrawingService;
use App\Services\WorkItemService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Sprint 24 — demo helpers for the execution flow. `skipToProduction()`
 * puts a freshly opened demo project where every project opened before
 * Sprint 24 is (PRODUKSI, Gambar Kerja done), so the older demo scenarios
 * keep their tasks and daily work; the other steps walk the real services
 * for the scenarios that show each phase.
 */
final class ExecutionFlowDemo
{
    /** A project that was already running: Gambar Kerja final, phase PRODUKSI since its start. */
    public static function skipToProduction(Project $project): Project
    {
        $project->workingDrawing()->update([
            'status' => WorkingDrawingStatus::Selesai->value,
            'drawing_urls' => ['https://drive.google.com/drive/folders/demo-gambar-kerja'],
            'completed_at' => $project->start_date ?? now(),
        ]);
        $project->update([
            'phase' => ProjectPhase::Produksi->value,
            'phase_changed_at' => $project->start_date ?? now(),
            'production_started_at' => $project->start_date ?? now(),
        ]);

        return $project->fresh();
    }

    /** GAMBAR_KERJA → TM through the real service: the Kepala Desain assigns, the architect saves a link and finishes. */
    public static function finishWorkingDrawing(Project $project): Project
    {
        $service = app(WorkingDrawingService::class);
        $head = User::role('KEPALA_DESAIN')->where('is_active', true)->firstOrFail();
        $architect = User::role('DESIGNER')->where('is_active', true)->whereKeyNot($head->id)->first() ?? $head;
        $drawing = $project->workingDrawing()->firstOrFail();

        $service->assign($drawing, ['pic_id' => $architect->id, 'start_date' => now()->subDays(4)->toDateString(), 'target_hari' => 5], $head);
        $service->updateLinks($drawing, ['drawing_urls' => ['https://drive.google.com/drive/folders/demo-gambar-kerja'], 'note' => 'Gambar kerja lengkap: denah, tampak, detail.'], $architect);
        $service->complete($drawing, $architect);

        return $project->fresh();
    }

    /**
     * The TM (PM) with its Timeline already on the project: a material line
     * planned by the Pengawas, minutes, attendees, Jasa Pengawas; `complete`
     * also presses "TM Selesai" (→ PERSIAPAN).
     */
    public static function holdMeeting(Project $project, User $pm, bool $complete = true): Project
    {
        $supervisor = $project->supervisors()->firstOrFail();
        $lastEnd = $project->milestones()->max('target_date');

        app(ProjectMaterialService::class)->plan($project, [
            'material_id' => self::material()->id,
            'source' => 'GUDANG',
            'qty_planned' => 4,
        ], $supervisor);

        // Sprint 29 Sub 08 — the Pengawas sets the Satuan Upah per RAB item (here ≈ 15 % of its price, rounded).
        $wages = app(WorkItemService::class);
        $project->workItems()->with('quotationItem')->get()->each(fn ($item) => $wages->update($item, [
            'unit_wage' => max(1000, round((float) $item->quotationItem->unit_price * 0.15, -3)),
        ], $supervisor));

        $service = app(TechnicalMeetingService::class);
        $service->save($project, [
            'meeting_date' => now()->subDays(2)->toDateString(),
            'notes' => "Kick-off produksi: urutan kerja per timeline, bahan dari gudang, finishing HPL.\nTarget harian dicek Pengawas, laporan mingguan tiap Jumat.",
            'attendees' => [
                ['user_id' => $pm->id],
                ['user_id' => $supervisor->id],
                $project->team_lead_id ? ['user_id' => $project->team_lead_id] : ['name' => $project->team_lead_name ?? 'Kepala Tim'],
            ],
            'fee_total' => 6_000_000,
            'dp_percent' => 10,
            'end_date' => $lastEnd ? substr((string) $lastEnd, 0, 10) : now()->addDays(30)->toDateString(),
        ], $pm);

        if ($complete) {
            $service->complete($project, $pm);
        }

        return $project->fresh();
    }

    /** PERSIAPAN → PRODUKSI: the Pengawas asks for the DP, the PM approves (`approve` false leaves it waiting). */
    public static function requestDp(Project $project, User $pm, bool $approve = true): Project
    {
        $service = app(SupervisorPaymentService::class);
        $payment = $service->requestDp($project, $project->supervisors()->firstOrFail());

        if ($approve) {
            $service->decide($payment, true, null, null, $pm);
        }

        return $project->fresh();
    }

    /** A timeline from `$startOffset` to `$endOffset` days from today (start_date set explicitly). */
    public static function timeline(Project $project, string $name, int $startOffset, int $endOffset): Milestone
    {
        return $project->milestones()->create([
            'name' => $name,
            'start_date' => now()->addDays($startOffset)->toDateString(),
            'target_date' => now()->addDays($endOffset)->toDateString(),
            'order' => (int) $project->milestones()->max('order') + 1,
        ]);
    }

    /**
     * Sprint 37 — the demo projects' one work plan, fitting their RAB (Kitchen
     * Set Custom · Partisi Ruangan 2 unit · Instalasi Listrik & Lampu): each
     * timeline holds its RAB items (= their schedule on the Lembar Progres),
     * day 0 = `$start` days from today.
     *
     * key => [name, first day, last day, RAB item descriptions]
     */
    private const TIMELINES = [
        'prep' => ['Persiapan & Potong Bahan', 0, 2, []],
        'kitchen' => ['Produksi Kitchen Set Custom', 3, 12, ['Kitchen Set Custom']],
        'partisi' => ['Produksi Partisi Ruangan', 3, 8, ['Partisi Ruangan']],
        'install' => ['Pemasangan & Instalasi di Lokasi', 13, 17, ['Kitchen Set Custom', 'Partisi Ruangan', 'Instalasi Listrik & Lampu']],
    ];

    /**
     * [day, timeline, title, crew member, RAB item?] — each target inside its timeline, on a working day.
     * Sprint 38 — a timeline with one RAB item gives it to its targets by itself; on the installation
     * timeline (three items) the item is named, the first and last target have none.
     */
    private const TARGETS = [
        [0, 'prep', 'Cek ukuran lapangan & cocokkan gambar kerja', 'fieldstaff'],
        [1, 'prep', 'Ambil bahan dari gudang (MDF, HPL, engsel, rel laci)', 'rudi'],
        [2, 'prep', 'Potong MDF & multiplek sesuai gambar kerja', 'fieldstaff'],
        [3, 'kitchen', 'Rakit badan kabinet bawah kitchen set', 'budi'],
        [5, 'kitchen', 'Rakit kabinet atas kitchen set', 'budi'],
        [7, 'kitchen', 'Pasang pintu, engsel soft-close & rel laci', 'yanto'],
        [10, 'kitchen', 'Finishing HPL & cek kerataan kitchen set', 'fieldstaff'],
        [3, 'partisi', 'Rakit rangka partisi ruangan (2 unit)', 'slamet'],
        [5, 'partisi', 'Pasang panel & tempel HPL partisi', 'slamet'],
        [7, 'partisi', 'Finishing tepi (edging) partisi', 'rudi'],
        [13, 'install', 'Muat & kirim kitchen set dan partisi ke lokasi', 'rudi'],
        [14, 'install', 'Pasang kitchen set di lokasi', 'budi', 'Kitchen Set Custom'],
        [15, 'install', 'Pasang partisi ruangan di lokasi', 'slamet', 'Partisi Ruangan'],
        [16, 'install', 'Instalasi listrik & lampu', 'yanto', 'Instalasi Listrik & Lampu'],
        [17, 'install', 'Cek akhir & bersihkan lokasi', 'fieldstaff'],
    ];

    /** Kepala Tim per RAB item. */
    private const LEADS = [
        'Kitchen Set Custom' => 'budi',
        'Partisi Ruangan' => 'slamet',
        'Instalasi Listrik & Lampu' => 'yanto',
    ];

    /** @return array<string, User> the demo crew (DatabaseSeeder / DemoDataSeeder accounts) */
    public static function crew(): array
    {
        return [
            'fieldstaff' => User::where('username', 'fieldstaff')->firstOrFail(),
            'budi' => User::where('username', 'budi')->firstOrFail(),
            'rudi' => User::where('name', 'Rudi Hartono')->firstOrFail(),
            'slamet' => User::where('name', 'Slamet Wijaya')->firstOrFail(),
            'yanto' => User::where('name', 'Yanto Kurniawan')->firstOrFail(),
        ];
    }

    /**
     * The timelines of the plan with their RAB items (through MilestoneService,
     * like the Timeline form).
     *
     * @return array<string, Milestone>
     */
    public static function workPlan(Project $project, int $start): array
    {
        $service = app(MilestoneService::class);
        $items = self::itemIds($project);

        return collect(self::TIMELINES)->map(fn (array $row) => $service->create($project, [
            'name' => $row[0],
            'start_date' => now()->addDays($start + $row[1])->toDateString(),
            'target_date' => now()->addDays($start + $row[2])->toDateString(),
            'work_item_ids' => collect($row[3])->map(fn (string $description) => $items->get($description))->filter()->values()->all(),
        ]))->all();
    }

    /** A real Kepala Tim per RAB item (the Pengawas sets it on the Upah Tukang card). */
    public static function assignLeads(Project $project, User $pengawas): void
    {
        $crew = self::crew();
        $wages = app(WorkItemService::class);

        $project->workItems()->with('quotationItem:id,description')->get()->each(function ($item) use ($crew, $wages, $pengawas) {
            if ($lead = self::LEADS[$item->quotationItem->description] ?? null) {
                $wages->update($item, ['team_lead_id' => $crew[$lead]->id], $pengawas);
            }
        });
    }

    /** @return Collection<string, int> work item id per RAB item description */
    public static function itemIds(Project $project): Collection
    {
        return $project->workItems()->with('quotationItem:id,description')->get()
            ->mapWithKeys(fn ($item) => [$item->quotationItem->description => $item->id]);
    }

    /**
     * The plan's daily targets (the Pengawas plans them after the TM), day 0
     * = `$dayZero` (the workPlan() start). A day falling on Sunday moves to
     * Saturday (or Monday at a timeline's start).
     *
     * @param  array<string, Milestone>  $timelines
     * @return list<array{task: Task, date: Carbon}>
     */
    public static function plannedTargets(Project $project, array $timelines, Carbon $dayZero, User $pengawas): array
    {
        $crew = self::crew();
        $tasks = app(TaskService::class);
        $items = self::itemIds($project);

        return collect(self::TARGETS)->map(function (array $row) use ($project, $timelines, $dayZero, $pengawas, $crew, $tasks, $items) {
            [$day, $key, $title, $who] = $row;
            $item = $row[4] ?? null;
            $timeline = $timelines[$key];
            $date = $dayZero->copy()->startOfDay()->addDays($day);

            if ($date->isSunday()) {
                $date = $date->copy()->subDay()->gte($timeline->start_date) ? $date->subDay() : $date->addDay();
            }

            $task = $tasks->create($project, [
                'milestone_id' => $timeline->id,
                'title' => $title,
                'assignee_id' => $crew[$who]->id,
                'due_date' => $date->toDateString(),
                'priority' => 'MEDIUM',
                ...($item ? ['project_work_item_id' => $items->get($item)] : []),
            ], $pengawas);

            return ['task' => $task, 'date' => $date];
        })->all();
    }

    /**
     * The Tukang reports a target "Selesai" on its own day; the Pengawas
     * approves it (or leaves it waiting to be checked).
     */
    public static function finishTarget(Task $task, Carbon $date, User $pengawas, bool $approve = true): Task
    {
        Carbon::setTestNow($date->copy()->setTime(16, 0));
        $task = app(TaskService::class)->updateStatus($task, ['status' => 'DONE'], $task->assignee);

        if ($approve && $task->status === TaskStatus::Pengecekan) {
            Carbon::setTestNow($date->copy()->setTime(17, 0));
            $task = app(TaskService::class)->approve($task, $pengawas);
        }

        Carbon::setTestNow();

        return $task;
    }

    /** One catalog item the demo plans from (through MaterialCatalogService — "satu pintu"). */
    private static function material(): Material
    {
        // Any catalog item already there (MaterialCatalogSeeder has some) — a new
        // one only on an empty catalog, so the demo never adds a near-duplicate.
        return Material::query()->where('is_active', true)->orderBy('id')->first()
            ?? app(MaterialCatalogService::class)->create([
                'material_category_id' => MaterialCategory::where('code_prefix', 'BHP')->value('id') ?? MaterialCategory::value('id'),
                'base_name' => 'Paku Tembak',
                'spec' => 'F30',
                'brand' => null,
                'unit_id' => Unit::where('code', 'pcs')->value('id') ?? Unit::value('id'),
                'cost_price' => 35_000,
                'sell_price' => 45_000,
                'min_stock' => 2,
                'similar_reason' => 'Data demo',
            ], User::role('LOGISTICS')->firstOrFail());
    }
}
