<?php

namespace App\Services;

use App\Enums\MaterialRequestStatus;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectPhase;
use App\Enums\ProjectStatus;
use App\Enums\SiteFindingStatus;
use App\Enums\SupervisorPaymentKind;
use App\Enums\SupervisorPaymentStatus;
use App\Enums\TaskStatus;
use App\Enums\WeeklyReportStatus;
use App\Enums\WorkItemQaStatus;
use App\Models\Project;
use App\Models\ProjectWorkItem;
use App\Models\User;
use App\Support\Quantity;

/**
 * Sprint 33 — "Langkah Kerja": the running phase's work in the order it is
 * done (after the TM: upah per item RAB → jadwal per item → daily target →
 * bahan → DP), each with who does it, whether it is done and where it is
 * filled (tab / view / card). Shown at the top of Detail Proyek so nobody
 * scrolls to find the next thing; the cards themselves are unchanged.
 *
 * States: `done`, `current` (do this now — the first open step, or a step
 * waiting on someone), `ongoing` (recurring work with nothing pending),
 * `todo` (later in this phase).
 */
class ProjectStepsService
{
    public function __construct(
        private WorkItemService $workItemService,
        private TechnicalMeetingService $technicalMeetingService,
        private WorkItemQaService $workItemQaService,
    ) {}

    /** @return array{phase: string, phase_label: string, items: list<array<string, mixed>>, done: int, total: int, next: ?array{phase: string, title: string}}|null */
    public function for(Project $project, User $user): ?array
    {
        if ($project->status !== ProjectStatus::Active) {
            return null;
        }

        $phase = $project->currentPhase();
        $perItem = $this->workItemService->hasWorkItems($project);
        $inputs = $user->can('supervise', $project) || $project->isAdministeredBy($user);
        $supervises = $user->can('supervise', $project);
        $approves = $user->can('approveExecution', $project);

        $steps = match ($phase) {
            ProjectPhase::GambarKerja => $this->drawing($project, $user),
            ProjectPhase::Tm => $this->meeting($project, $user, $inputs),
            ProjectPhase::Persiapan => $this->preparation($project, $perItem, $inputs, $supervises, $approves),
            ProjectPhase::Produksi => $this->production($project, $perItem, $inputs, $supervises, $approves),
            ProjectPhase::Pemasangan => $this->installation($project, $perItem, $supervises, $approves, $user),
        };

        $items = $this->withStates($steps);
        $next = collect(ProjectPhase::cases())->first(fn (ProjectPhase $step) => $step->rank() === $phase->rank() + 1);

        return [
            'phase' => $phase->value,
            'phase_label' => $phase->label(),
            'items' => $items,
            'done' => collect($items)->where('state', 'done')->count(),
            'total' => count($items),
            'next' => $next ? ['phase' => $next->value, 'title' => $next->label()] : ['phase' => 'SELESAI', 'title' => 'Proyek selesai'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function drawing(Project $project, User $user): array
    {
        $drawing = $project->workingDrawing()->first();

        return [
            $this->step('drawing', 'Selesaikan gambar kerja', 'Kepala Desain / Arsitek', $drawing?->completed_at !== null,
                $drawing?->deadline ? 'Tenggat '.$drawing->deadline->locale('id')->translatedFormat('j M Y') : null,
                ['tab' => 'execution', 'anchor' => 'gambar-kerja'], $drawing !== null && $user->can('view', $drawing) && $user->hasAnyRole(['KEPALA_DESAIN', 'DESIGNER'])),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function meeting(Project $project, User $user, bool $inputs): array
    {
        $meeting = $project->technicalMeeting()->withCount('attendees')->first();
        $timelines = $project->milestones()->count();
        $planned = $this->technicalMeetingService->plannedMaterials($project)->count();
        $edits = $user->can('editMeeting', $project);
        $notesDone = $meeting?->meeting_date !== null && filled($meeting?->notes) && ($meeting?->attendees_count ?? 0) > 0;
        $missing = collect($this->technicalMeetingService->requirements($project))->reject(fn (array $row) => $row['done'])->pluck('label')->all();
        $paid = $this->workItemService->hasWorkItems($project)
            ? ProjectWorkItem::where('project_id', $project->id)->get(['id', 'unit_wage'])
            : null;

        return [
            $this->step('tm-schedule', 'Jadwalkan Technical Meeting', 'PM', $meeting?->meeting_date !== null,
                $meeting?->meeting_date ? $meeting->meeting_date->locale('id')->translatedFormat('l, j M Y') : 'Tanggal TM belum diisi.',
                ['tab' => 'execution', 'anchor' => 'tm'], $edits),
            $this->step('tm-timeline', 'Susun timeline & tanggal selesai proyek', 'PM / Admin Proyek', $timelines > 0 && $project->end_date !== null,
                $timelines > 0 ? "{$timelines} timeline".($project->end_date ? '' : ' · tanggal selesai belum diisi') : 'Belum ada timeline.',
                ['tab' => 'execution', 'anchor' => 'tm'], $edits),
            $this->step('tm-notes', 'Isi notulen & peserta TM', 'PM / Admin Proyek', $notesDone,
                $notesDone ? "Notulen terisi · {$meeting->attendees_count} peserta" : (filled($meeting?->notes) ? 'Peserta belum diisi.' : 'Notulen belum diisi.'),
                ['tab' => 'execution', 'anchor' => 'tm'], $edits),
            // The closing step: its button is "TM Selesai" itself (WorkStepsPanel `action`).
            $this->step('tm-complete', 'Tekan TM Selesai', 'PM', false,
                $missing === [] ? 'Semua syarat lengkap — tekan tombol di bawah.' : 'Belum lengkap: '.implode(', ', $missing).'.',
                ['tab' => 'execution', 'anchor' => 'tm'], $user->can('runMeeting', $project), action: 'completeMeeting'),
            // Sprint 33 — the Pengawas's part never holds "TM Selesai" (also after the TM, up to the DP).
            ...($paid ? [
                $this->step('tm-wages', 'Isi upah tukang per item RAB', 'Pengawas', $paid->contains(fn (ProjectWorkItem $item) => $item->hasWage()),
                    $paid->filter(fn (ProjectWorkItem $item) => $item->hasWage())->count().' dari '.$paid->count().' item berupah',
                    ['tab' => 'execution', 'anchor' => 'tm-upah'], $inputs, optional: true),
            ] : []),
            $this->step('tm-materials', 'Rencanakan bahan yang digunakan', 'Pengawas', $planned > 0,
                $planned > 0 ? "{$planned} bahan direncanakan" : 'Belum ada bahan.',
                ['tab' => 'execution', 'anchor' => 'tm-bahan'], $inputs, optional: true),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function preparation(Project $project, bool $perItem, bool $inputs, bool $supervises, bool $approves): array
    {
        $items = $perItem ? ProjectWorkItem::where('project_id', $project->id)->get(['id', 'unit_wage', 'production_start']) : collect();
        $paid = $items->filter(fn (ProjectWorkItem $item) => $item->hasWage());
        $unscheduled = $paid->whereNull('production_start')->count();
        $milestones = $project->milestones()->withCount('tasks')->get(['id']);
        $emptyTimelines = $milestones->where('tasks_count', 0)->count();
        $planned = $this->technicalMeetingService->plannedMaterials($project)->count();
        $drafts = $project->projectMaterials()->fromPlan()->where('request_status', MaterialRequestStatus::Direncanakan->value)->count();
        $dp = $project->supervisorPayments()->where('kind', SupervisorPaymentKind::Dp->value)
            ->where('status', '!=', SupervisorPaymentStatus::Ditolak->value)->latest('id')->first();

        return [
            ...($perItem ? [
                $this->step('wages', 'Tentukan upah tukang per item RAB', 'Pengawas / Admin Proyek', $paid->isNotEmpty(),
                    $paid->isNotEmpty() ? "{$paid->count()} dari {$items->count()} item berupah" : 'Isi Satuan Upah & Kepala Tim tiap item RAB.',
                    ['tab' => 'items', 'view' => 'upah'], $inputs),
                $this->step('schedule', 'Atur jadwal kerja tiap item', 'Pengawas / Admin Proyek', $paid->isNotEmpty() && $unscheduled === 0,
                    $paid->isEmpty() ? 'Setelah upah terisi.' : ($unscheduled === 0 ? 'Semua item berupah terjadwal' : "{$unscheduled} item belum dijadwalkan"),
                    ['tab' => 'items', 'view' => 'progres'], $inputs),
            ] : []),
            $this->step('targets', 'Buat daily target tiap timeline', 'Pengawas / Admin Proyek', $milestones->isNotEmpty() && $emptyTimelines === 0,
                $milestones->isEmpty() ? 'Belum ada timeline.' : ($emptyTimelines === 0 ? 'Semua timeline punya daily target' : "{$emptyTimelines} timeline belum punya daily target"),
                ['tab' => 'task', 'view' => 'hari'], $inputs),
            $this->step('materials', 'Lengkapi bahan & ajukan ke Logistik', 'Pengawas', $planned > 0 && $drafts === 0,
                $planned === 0 ? 'Belum ada rencana bahan.' : ($drafts > 0 ? "{$drafts} bahan masih draf — belum diajukan" : "{$planned} bahan direncanakan"),
                ['tab' => 'material'], $inputs),
            $this->step('dp-request', 'Ajukan DP upah tukang', 'Pengawas', $dp !== null,
                $dp ? 'Diajukan Rp '.number_format((float) $dp->amount, 0, ',', '.') : 'Bisa diajukan setelah syarat DP lengkap.',
                ['tab' => 'execution', 'anchor' => 'syarat-dp'], $supervises),
            $this->step('dp-approve', 'Setujui DP = Start Produksi', 'PM', false,
                $dp ? 'Menunggu keputusan PM.' : 'Setelah Pengawas mengajukan DP.',
                ['tab' => 'execution', 'anchor' => 'dp'], $approves, attention: $dp?->status === SupervisorPaymentStatus::Diajukan),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function production(Project $project, bool $perItem, bool $inputs, bool $supervises, bool $approves): array
    {
        $threshold = ProductionService::departureThreshold();
        $progress = $project->progressFigure()['progress'] ?? 0.0;

        return [
            ...$this->recurring($project, $perItem, $inputs, $supervises, $approves),
            $this->step('departure', "Berangkat ke lokasi (progres ≥ {$threshold} %)", 'PM', $project->departure_approved_at !== null,
                'Progres sekarang '.Quantity::format($progress).' %',
                ['tab' => 'execution', 'anchor' => 'berangkat'], $approves, attention: $progress >= $threshold, later: $progress < $threshold),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function installation(Project $project, bool $perItem, bool $supervises, bool $approves, User $user): array
    {
        $open = $project->siteFindings()->where('status', SiteFindingStatus::Terbuka->value)->count();
        $itemQa = $project->usesItemQa();

        if ($itemQa) {
            $statuses = $this->workItemQaService->statuses($project);
            $passed = $statuses->where('status', WorkItemQaStatus::Lolos)->count();
            $waiting = $statuses->where('status', WorkItemQaStatus::Menunggu)->count();
            $qa = $this->step('qa', 'QC tiap item RAB (Lolos / Perlu perbaikan)', 'Pengawas', $statuses->isNotEmpty() && $passed === $statuses->count(),
                "{$passed} dari {$statuses->count()} item lolos".($waiting > 0 ? " · {$waiting} menunggu QC" : ''),
                ['tab' => 'items', 'view' => 'qc'], $user->can('reviewItemQa', $project), attention: $waiting > 0);
        } else {
            $milestones = $project->milestones()->get(['id', 'status']);
            $completed = $milestones->where('status', MilestoneStatus::Completed)->count();
            $qa = $this->step('qa', 'QC tiap timeline', 'Pengawas', $milestones->isNotEmpty() && $completed === $milestones->count(),
                "{$completed} dari {$milestones->count()} timeline lolos",
                ['tab' => 'milestone', 'view' => 'qc'], $user->hasRole('QA'), attention: $milestones->where('status', MilestoneStatus::QaWaiting)->isNotEmpty());
        }

        return [
            ...$this->recurring($project, $perItem, $supervises || $project->isAdministeredBy($user), $supervises, $approves),
            $qa,
            $this->step('findings', 'Tutup temuan lokasi', 'Pengawas / PM', $open === 0,
                $open === 0 ? 'Tidak ada temuan terbuka' : "{$open} temuan terbuka",
                ['tab' => 'execution', 'anchor' => 'temuan'], $supervises || $approves),
        ];
    }

    /**
     * Produksi & Pemasangan: the daily targets checked, the pengecekan sent,
     * the PM's decision — recurring, `current` only while something waits.
     *
     * @return list<array<string, mixed>>
     */
    private function recurring(Project $project, bool $perItem, bool $inputs, bool $supervises, bool $approves): array
    {
        $awaitingCheck = $project->tasks()->where('status', TaskStatus::Pengecekan->value)->count();
        $latest = $project->weeklyReports()->first();
        $pending = $latest?->status === WeeklyReportStatus::Diajukan;
        $sheet = $perItem ? ['tab' => 'items', 'view' => 'progres'] : ['tab' => 'execution', 'anchor' => 'laporan-mingguan'];
        $thisWeek = $latest && $latest->week_start->isSameDay(app(WeeklyReportService::class)->weekOf($project)['week_start']);

        return [
            $this->step('daily', 'Cek daily target tukang (Setujui / Kembalikan)', 'Pengawas / Admin Proyek', false,
                $awaitingCheck > 0 ? "{$awaitingCheck} daily target menunggu dicek" : 'Tidak ada yang menunggu dicek',
                ['tab' => 'task', 'view' => 'board'], $inputs, ongoing: true, attention: $awaitingCheck > 0),
            $this->step('check', $perItem ? 'Isi progres per item & ajukan pencairan' : 'Kirim laporan mingguan', 'Pengawas', false,
                $pending ? 'Terkirim — menunggu ACC PM' : ($latest ? 'Terakhir: '.$latest->label().($thisWeek ? '' : ' · minggu ini belum') : 'Belum ada pengecekan'),
                $sheet, $supervises, ongoing: true, attention: ! $pending && ! $thisWeek && WeeklyReportService::isDue(daysBefore: 1)),
            $this->step('check-decide', 'ACC pengecekan & pencairan upah', 'PM', false,
                $pending ? "{$latest->label()} menunggu keputusan" : 'Tidak ada yang menunggu',
                $sheet, $approves, ongoing: true, attention: $pending),
        ];
    }

    /** @return array<string, mixed> */
    /** `later` = not possible yet (e.g. below 70 %): never the first open step. */
    /** `optional` = done beside the phase's flow, never holding its closing step (state `optional` while open). */
    private function step(string $key, string $title, string $who, bool $done, ?string $detail, array $target, bool $mine, bool $ongoing = false, bool $attention = false, bool $later = false, bool $optional = false, ?string $action = null): array
    {
        return compact('key', 'title', 'who', 'done', 'detail', 'target', 'mine', 'ongoing', 'attention', 'later', 'optional', 'action');
    }

    /**
     * The first open (one-time) step is `current`; a step waiting on someone
     * (`attention`) is `current` too; recurring work otherwise `ongoing`.
     *
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private function withStates(array $steps): array
    {
        $firstOpen = collect($steps)->search(fn (array $step) => ! $step['done'] && ! $step['ongoing'] && ! $step['later'] && ! $step['optional']);

        return collect($steps)->map(function (array $step, int $index) use ($firstOpen) {
            $state = match (true) {
                $step['done'] => 'done',
                $step['attention'], $index === $firstOpen && ! $step['ongoing'] => 'current',
                $step['ongoing'] => 'ongoing',
                $step['optional'] => 'optional',
                default => 'todo',
            };
            unset($step['done'], $step['ongoing'], $step['attention'], $step['later'], $step['optional']);

            return [...$step, 'state' => $state];
        })->values()->all();
    }
}
