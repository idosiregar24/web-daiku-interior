<?php

namespace Database\Seeders\Demo;

use App\Enums\DailyActivityReviewStatus;
use App\Models\Material;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectWorkItem;
use App\Models\Task;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Services\DailyActivityService;
use App\Services\MilestoneService;
use App\Services\ProjectMaterialService;
use App\Services\TaskService;
use App\Services\WageSplitService;
use App\Services\WorkItemService;
use App\Support\DailyFormSchedule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Sprint 29 — everything of the revision the demo Pengawas (`qa` /
 * qa@daikuinterior.com) can open, on top of WorkflowScenarioSeeder's
 * execution projects (Bambang TM · Siska Persiapan · Hendra Produksi with
 * two Pengawas · Dewi Pemasangan with QA per item):
 *
 * - Bambang (TM): part of the Upah Tukang filled, a Rencana Bahan draft
 *   (catalog + custom) → "Ajukan ke Logistik (2 bahan)".
 * - Siska / Hendra / Dewi: the Rencana Bahan at Diajukan / Dipesan / Disetujui.
 * - Hendra: last week's pengecekan (50 %) approved & paid; this week's is
 *   filled as a draft and sent on Friday / Saturday (Sprint 36 — once a
 *   week; typing +20 % on every item → 70 % unlocks "Berangkat ke Lokasi");
 *   one daily target sent back to the Tukang ("Kembalikan"), two waiting.
 * - Sprint 32 — Tim Tukang templates of File D (Tim Putra, Tim Evan, Tim
 *   Yusup); Hendra's "Tim Putra" splits per person, "Tim Yusuf" is left for
 *   the Pengawas to pick a template.
 * - Sprint 35: the wage items sit in the projects' timelines (their schedule).
 * - Aktivitas Harian of `qa`: every working day of last month (two left
 *   empty, one clarification — KpiDemoSeeder computes its KPI), today, the
 *   previous working day (HR asks a clarification) and the day before (Sesuai).
 *
 * Runs through the real services, like the other demo seeders.
 */
class PengawasPreviewSeeder extends Seeder
{
    public function run(): void
    {
        $qa = User::where('username', 'qa')->firstOrFail();
        $pm = User::where('username', 'pm')->firstOrFail();
        $logistics = User::where('username', 'logistics')->firstOrFail();
        $tukang = User::where('username', 'fieldstaff')->firstOrFail();
        $hr = User::where('username', 'hr')->firstOrFail();
        $project = fn (string $client) => Project::where('name', "Proyek {$client}")->firstOrFail();

        $materials = app(ProjectMaterialService::class);

        // ── Bambang (TM): Upah Tukang half filled, Rencana Bahan still a draft ──
        $bambang = $project('Bambang Sutrisno');
        $wages = app(WorkItemService::class);
        $bambang->workItems()->with('quotationItem')->orderBy('id')->get()
            ->filter(fn (ProjectWorkItem $item, int $index) => $index % 2 === 0)
            ->each(fn (ProjectWorkItem $item) => $wages->update($item, [
                'unit_wage' => max(1000, round((float) $item->quotationItem->unit_price * 0.15, -3)),
                'team_lead_name' => 'Tim Pak Darto',
            ], $qa));
        $catalog = Material::active()->orderBy('id')->skip(1)->firstOrFail();
        $materials->plan($bambang, ['material_id' => $catalog->id, 'source' => 'GUDANG', 'qty_planned' => 6], $qa);
        $materials->planCustom($bambang, [
            'name' => 'Engsel soft-close Blum',
            'spec' => 'Clip top 110°, full overlay',
            'unit_id' => Unit::where('code', 'pcs')->value('id'),
            'qty' => 12,
            'note' => 'Untuk 6 pintu kabinet bawah — belum ada di katalog.',
        ], $qa);

        // ── Rencana Bahan through Logistik: Diajukan (Siska) · Dipesan (Hendra) · Disetujui (Dewi) ──
        $materials->submitPlan($project('Siska Amelia'), $qa);

        $vendor = Vendor::where('is_active', true)->orderBy('id')->value('id');
        foreach (['Hendra Gunawan', 'Dewi Lestari'] as $client) {
            $lines = $project($client);
            $materials->submitPlan($lines, $qa);
            $ids = $lines->projectMaterials()->pluck('id')->all();
            $materials->decidePlan($ids, 'approve', ['source' => 'PEMBELIAN', 'vendor_id' => $vendor], $logistics);

            if ($client === 'Hendra Gunawan') {
                $materials->markOrdered($ids, [
                    'expected_at' => now()->addDays(3)->toDateString(),
                    'vendor_id' => $vendor,
                    'note' => 'Dikirim langsung ke workshop.',
                ], $logistics);
            }
        }

        // ── Sprint 35 — each item's schedule is its timeline: the wage items go into the projects' timelines (round robin) ──
        $milestoneService = app(MilestoneService::class);
        foreach (['Hendra Gunawan', 'Siska Amelia', 'Dewi Lestari'] as $client) {
            $timelines = $project($client)->milestones()->reorder()->orderBy('order')->get();
            $items = $project($client)->workItems()->where('unit_wage', '>', 0)->orderBy('id')->pluck('id');

            if ($timelines->isEmpty()) {
                continue;
            }

            $timelines->each(fn (Milestone $timeline, int $index) => $milestoneService->update($timeline, [
                'work_item_ids' => $items->filter(fn (int $id, int $position) => $position % $timelines->count() === $index)->values()->all(),
            ]));
        }

        // ── Hendra: last week's pengecekan approved (WorkflowScenarioSeeder); this week's is filled as a
        // draft and sent on Friday / Saturday (Sprint 36). One target sent back to the Tukang. ──
        $hendra = $project('Hendra Gunawan');

        $tasks = app(TaskService::class);
        $hpl = Task::where('project_id', $hendra->id)->where('title', 'Lapis HPL meja')->firstOrFail();
        $tasks->updateStatus($hpl, ['status' => 'DONE', 'kendala' => 'HPL sisi depan sudah, samping besok.'], $tukang);
        $tasks->returnToStaff($hpl->fresh(), 'Sisi samping meja belum dilapis — lanjutkan sampai rapi, lalu tandai Selesai lagi.', $qa);

        // ── Aktivitas Harian of the Pengawas ──
        $activities = app(DailyActivityService::class);
        $today = DailyFormSchedule::now()->startOfDay();
        $yesterday = DailyFormSchedule::previousWorkDay($today);
        $dayBefore = DailyFormSchedule::previousWorkDay($yesterday);
        $dewi = $project('Dewi Lestari');

        foreach ([
            [$dayBefore, [
                ['Cek progres rangka meja & laci di workshop', $hendra->id],
                ['Koordinasi bahan HPL dengan Logistik', null],
            ]],
            [$yesterday, [
                ['QA item kitchen set di lokasi — 1 item perlu perbaikan', $dewi->id],
                ['Isi pengecekan % per item minggu ini', $hendra->id],
            ]],
        ] as [$day, $entries]) {
            Carbon::setTestNow($day->copy()->setTime(16, 0));
            foreach ($entries as [$description, $projectId]) {
                $activities->create($qa, ['description' => $description, 'project_id' => $projectId]);
            }
            Carbon::setTestNow();
        }

        $activities->create($qa, ['description' => 'Periksa daily target yang menunggu dicek', 'project_id' => $hendra->id]);

        // Last month — the KPI month KpiDemoSeeder computes (Dodi joined on its 1st).
        $month = now()->subMonthNoOverflow()->startOfMonth();
        $workDays = collect(range(0, $month->daysInMonth - 1))
            ->map(fn (int $offset) => $month->copy()->addDays($offset))
            ->filter(fn (Carbon $day) => DailyFormSchedule::isWorkDay($day))
            ->values();
        foreach ($workDays as $index => $day) {
            // Two working days left empty → "% hari mengisi" below 100.
            if (in_array($index, [4, 13], true)) {
                continue;
            }
            Carbon::setTestNow($day->copy()->setTime(16, 0));
            $activities->create($qa, ['description' => $index % 2 === 0 ? 'Cek progres & daily target di workshop' : 'Pengecekan % per item dan koordinasi bahan', 'project_id' => null]);
            Carbon::setTestNow();
            $activities->review([
                'user_id' => $qa->id,
                'date' => $day->toDateString(),
                'status' => $index === 9 ? DailyActivityReviewStatus::PerluKlarifikasi->value : DailyActivityReviewStatus::Sesuai->value,
                'note' => $index === 9 ? 'Proyek mana yang dicek? Mohon disebutkan.' : null,
            ], $hr);
        }

        $activities->review(['user_id' => $qa->id, 'date' => $dayBefore->toDateString(), 'status' => DailyActivityReviewStatus::Sesuai->value], $hr);
        $activities->review([
            'user_id' => $qa->id,
            'date' => $yesterday->toDateString(),
            'status' => DailyActivityReviewStatus::PerluKlarifikasi->value,
            'note' => 'Item mana yang perlu perbaikan di proyek Dewi? Mohon sebutkan di aktivitas.',
        ], $hr);

        $this->seedWorkTeams($qa, $project('Hendra Gunawan'));
    }

    /** Sprint 32 — File D's three teams (bobot as in the sheet, names made up) and Hendra's split. */
    private function seedWorkTeams(User $qa, Project $hendra): void
    {
        $splits = app(WageSplitService::class);
        $member = fn (string $name, string $level, int $weight) => ['name' => $name, 'level' => $level, 'weight' => $weight, 'company' => false];
        $company = ['name' => null, 'level' => 'Perusahaan', 'weight' => 5, 'company' => true];

        $putra = $splits->saveTemplate(null, ['name' => 'Tim Putra', 'leader_name' => 'Putra', 'members' => [
            $member('Putra', 'Ketua Tim', 24), $member('Andi', 'Tukang 1', 17), $member('Bayu', 'Tukang 1', 17), $member('Candra', 'Tukang 2', 14), $company,
        ]], $qa);
        $splits->saveTemplate(null, ['name' => 'Tim Evan', 'leader_name' => 'Evan', 'members' => [
            $member('Evan', 'Ketua Tim', 24), $member('Dimas', 'Tukang 1', 18), $member('Eko', 'Tukang 2', 15), $member('Fajar', 'Helper 1', 10), $company,
        ]], $qa);
        $splits->saveTemplate(null, ['name' => 'Tim Yusup', 'leader_name' => 'Yusup', 'members' => [
            $member('Yusup', 'Ketua Tim', 24), $member('Gilang', 'Tukang 1', 18), $member('Hadi', 'Helper 1', 11), $member('Imam', 'Helper 1', 11), $member('Joko', 'Helper 2', 7), $company,
        ]], $qa);

        $splits->saveProjectSplit($hendra, ['team_label' => 'Tim Putra', 'work_team_id' => $putra->id], $qa);
    }
}
