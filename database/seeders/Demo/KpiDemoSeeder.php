<?php

namespace Database\Seeders\Demo;

use App\Enums\KpiIndicatorSource;
use App\Models\KpiScore;
use App\Models\KpiTemplate;
use App\Models\Position;
use App\Models\User;
use App\Services\KpiService;
use Database\Seeders\OrganizationStructureSeeder;
use Illuminate\Database\Seeder;

/**
 * SDM (Sprint 10, §3.3) demo — default KPI templates for the positions of
 * OrganizationStructureSeeder::STRUCTURE (the §3.3 defaults, decision #9),
 * then the previous 3 months: opened and computed through KpiService
 * (actor = first HR user), manual values filled, the 2 older months
 * closed, the latest left OPEN with one employee's manual values still
 * empty so the "belum lengkap" state is visible. Needs employees first
 * (DemoDataSeeder). Idempotent: existing templates/closed months are kept.
 */
class KpiDemoSeeder extends Seeder
{
    /**
     * position → indicators [name, metric_key|null (null = MANUAL), target, weight, direction].
     *
     * @var array<string, list<array{0: string, 1: string|null, 2: float|int, 3: float|int, 4?: string}>>
     */
    public const TEMPLATES = [
        'Marketing' => [
            ['Lead baru', 'lead_new_count', 10, 35],
            ['Konversi lead → deal', 'lead_conversion_rate', 40, 40],
            ['Follow-up terlambat', 'lead_overdue_followup_count', 2, 25, 'LOWER_BETTER'],
        ],
        'Estimator' => [
            ['Turnaround quotation', 'quotation_turnaround_days', 3, 25, 'LOWER_BETTER'],
            ['Quotation terkirim', 'quotation_sent_count', 6, 20],
            // Sprint 12 #9 — from the per-item RAB review.
            ['Item RAB lolos review pertama', 'estimator_first_pass_rate', 90, 25],
            ['RAB dikembalikan', 'estimator_returned_count', 1, 15, 'LOWER_BETTER'],
            ['Akurasi RAB', null, 95, 15],
        ],
        'Desainer Interior' => [
            ['Desain tepat jadwal', 'design_on_schedule_rate', 90, 30],
            ['Rata-rata hari delay', 'design_avg_delay_days', 2, 20, 'LOWER_BETTER'],
            ['Desain ACC klien', 'design_client_acc_count', 3, 20],
            ['Kualitas gambar', null, 90, 30],
        ],
        'Arsitek' => [
            ['Desain tepat jadwal', 'design_on_schedule_rate', 90, 30],
            ['Rata-rata hari delay', 'design_avg_delay_days', 2, 20, 'LOWER_BETTER'],
            ['Desain ACC klien', 'design_client_acc_count', 2, 20],
            ['Kualitas gambar', null, 90, 30],
        ],
        'Drafter' => [
            ['Ketepatan gambar kerja', null, 90, 60],
            ['Kerapian dokumen', null, 90, 40],
        ],
        'Project Manager' => [
            ['Milestone tepat waktu', 'milestone_on_time_rate', 90, 25],
            ['QA lolos pertama kali', 'qa_first_pass_rate', 80, 20],
            ['Proyek delay', 'project_delay_count', 1, 20, 'LOWER_BETTER'],
            // Sprint 12 #9 — RAB the PM approved that the CEO sent back.
            ['RAB di-ACC lalu dikembalikan CEO', 'pm_review_escaped_count', 1, 15, 'LOWER_BETTER'],
            ['Koordinasi tim', null, 90, 20],
        ],
        'Quality Assurance' => [
            ['Form QA diproses', 'qa_forms_processed_count', 10, 60],
            ['Ketelitian inspeksi', null, 90, 40],
        ],
        'Admin Finance' => [
            ['Termin tertagih tepat waktu', 'termin_on_time_rate', 90, 60],
            ['Ketepatan laporan', null, 90, 40],
        ],
        'Staf Gudang' => [
            ['Akurasi stok', null, 98, 60],
            ['Kerapian gudang', null, 90, 40],
        ],
        'Admin Kantor' => [
            ['Ketepatan administrasi', null, 90, 50],
            ['Pelayanan internal', null, 90, 50],
        ],
        'Staf SDM' => [
            ['Ketepatan data karyawan', null, 95, 50],
            ['Rekrutmen tepat waktu', null, 90, 50],
        ],
    ];

    public function run(): void
    {
        $service = app(KpiService::class);
        $hr = User::role('HR')->where('is_active', true)->orderBy('id')->first();

        if (! $hr) {
            $this->command?->warn('KpiDemoSeeder: tidak ada user HR — dilewati.');

            return;
        }

        $this->call(OrganizationStructureSeeder::class);

        foreach (self::TEMPLATES as $positionName => $indicators) {
            $position = Position::where('name', $positionName)->first();

            if (! $position || KpiTemplate::where('position_id', $position->id)->exists()) {
                continue;
            }

            $service->saveTemplate($position, array_map(fn (array $row) => [
                'name' => $row[0],
                'source' => $row[1] ? KpiIndicatorSource::Auto->value : KpiIndicatorSource::Manual->value,
                'metric_key' => $row[1],
                'target' => $row[2],
                'weight' => $row[3],
                'direction' => $row[4] ?? 'HIGHER_BETTER',
            ], $indicators), $hr);
        }

        // Deterministic "random" manual values — reproducible demo data.
        mt_srand(20261004);

        foreach ([3, 2, 1] as $monthsAgo) {
            $period = $service->openPeriod(now()->subMonthsNoOverflow($monthsAgo)->format('Y-m'), $hr);

            if ($period->isClosed()) {
                continue;
            }

            $service->compute($period, $hr);
            $latest = $monthsAgo === 1;
            $skipEmployee = $latest
                ? KpiScore::where('kpi_period_id', $period->id)->where('source', KpiIndicatorSource::Manual->value)->orderByDesc('employee_id')->value('employee_id')
                : null;

            KpiScore::where('kpi_period_id', $period->id)
                ->where('source', KpiIndicatorSource::Manual->value)
                ->whereNull('actual')
                ->when($skipEmployee, fn ($query) => $query->where('employee_id', '!=', $skipEmployee))
                ->get()
                ->each(fn (KpiScore $score) => $service->updateManualScore(
                    $score,
                    round((float) $score->target * mt_rand(70, 110) / 100, 1),
                    $hr,
                ));

            if (! $latest) {
                $service->close($period->fresh(), $hr);
            }
        }
    }
}
