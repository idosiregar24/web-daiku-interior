<?php

namespace Database\Seeders\Demo;

use App\Enums\ProjectPhase;
use App\Enums\WorkingDrawingStatus;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Unit;
use App\Models\User;
use App\Services\MaterialCatalogService;
use App\Services\ProjectMaterialService;
use App\Services\SupervisorPaymentService;
use App\Services\TechnicalMeetingService;
use App\Services\WorkingDrawingService;
use App\Services\WorkItemService;

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
                ['name' => $project->team_lead_name ?? 'Kepala Tim'],
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
