<?php

namespace App\Services;

use App\Enums\MaterialRequestStatus;
use App\Enums\NotificationType;
use App\Enums\ProjectPhase;
use App\Models\Project;
use App\Models\SupervisorFee;
use App\Models\TechnicalMeeting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 24 D3 — the Technical Meeting. While the project is at TM the PM
 * (or its Asisten PM) records the meeting — date, minutes, attendees (a
 * user or a typed name, E2), the Jasa Pengawas (total + DP %, D4) and the
 * project's Tanggal Selesai (E1) — and fills the Timeline on the Timeline
 * tab; the Pengawas fills the materials (rencana material). "TM Selesai"
 * checks everything is there and moves the project to PERSIAPAN.
 */
class TechnicalMeetingService
{
    public function __construct(
        private NotificationService $notificationService,
        private AuditLogService $auditLogService,
        private ProjectPhaseService $phaseService,
    ) {}

    /**
     * @param  array{meeting_date: string, notes?: ?string, attendees?: array<int, array{user_id?: int|string|null, name?: ?string}>, fee_total: numeric-string|int|float, dp_percent: numeric-string|int|float, end_date?: ?string}  $data
     */
    public function save(Project $project, array $data, User $actor): TechnicalMeeting
    {
        return DB::transaction(function () use ($project, $data, $actor) {
            $project = Project::lockForUpdate()->findOrFail($project->id);
            $this->phaseService->ensurePhase($project, ProjectPhase::Tm, 'Technical Meeting hanya bisa diisi saat proyek di tahap TM.');

            if (! $project->supervisor_id) {
                throw ValidationException::withMessages(['fee_total' => 'Tentukan Pengawas (QA/QC) proyek lewat Ubah Proyek dulu.']);
            }

            $meeting = TechnicalMeeting::firstOrNew(['project_id' => $project->id]);
            $before = $meeting->exists ? $this->snapshot($meeting, $project) : null;

            $meeting->fill([
                'meeting_date' => $data['meeting_date'],
                'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
                'recorded_by' => $actor->id,
            ])->save();

            $meeting->attendees()->delete();
            collect($data['attendees'] ?? [])
                ->map(fn (array $row) => filled($row['user_id'] ?? null)
                    ? ['user_id' => (int) $row['user_id'], 'name' => null]
                    : ['user_id' => null, 'name' => filled($row['name'] ?? null) ? trim($row['name']) : null])
                ->filter(fn (array $row) => $row['user_id'] !== null || $row['name'] !== null)
                ->unique(fn (array $row) => $row['user_id'] ? "u{$row['user_id']}" : 'n'.mb_strtolower($row['name']))
                ->each(fn (array $row) => $meeting->attendees()->create($row));

            SupervisorFee::updateOrCreate(['project_id' => $project->id], [
                'supervisor_id' => $project->supervisor_id,
                'total_amount' => round((float) $data['fee_total'], 2),
                'dp_percent' => round((float) $data['dp_percent'], 2),
                'set_by' => $actor->id,
            ]);

            if (array_key_exists('end_date', $data)) {
                $project->update(['end_date' => filled($data['end_date']) ? $data['end_date'] : null]);
            }

            $meeting->load('attendees.user:id,name');
            $this->auditLogService->record('technical_meeting.saved', $project, $before, $this->snapshot($meeting, $project->fresh()), $actor);

            return $meeting;
        });
    }

    /**
     * What "TM Selesai" needs (D3/E1) — shown as a checklist on the
     * Eksekusi tab and enforced by complete().
     *
     * @return list<array{key: string, label: string, done: bool}>
     */
    public function requirements(Project $project): array
    {
        $meeting = $project->technicalMeeting()->withCount('attendees')->first();
        $fee = $project->supervisorFee()->first();

        return [
            ['key' => 'notes', 'label' => 'Tanggal & notulen TM', 'done' => $meeting !== null && $meeting->meeting_date !== null && filled($meeting->notes)],
            ['key' => 'attendees', 'label' => 'Minimal satu peserta', 'done' => (int) ($meeting?->attendees_count ?? 0) > 0],
            ['key' => 'timeline', 'label' => 'Timeline (durasi) tersusun · PM', 'done' => $project->milestones()->exists()],
            ['key' => 'materials', 'label' => 'Bahan yang digunakan · Pengawas', 'done' => $this->plannedMaterials($project)->exists()],
            ['key' => 'fee', 'label' => 'Nilai jasa pengawas & DP %', 'done' => $fee !== null && (float) $fee->total_amount > 0],
            ['key' => 'end_date', 'label' => 'Tanggal Selesai proyek', 'done' => $project->end_date !== null],
        ];
    }

    /** "TM Selesai" — PM; every requirement met → PERSIAPAN, the Pengawas told to complete the DP conditions. */
    public function complete(Project $project, User $actor): Project
    {
        return DB::transaction(function () use ($project, $actor) {
            $project = Project::lockForUpdate()->findOrFail($project->id);
            $this->phaseService->ensurePhase($project, ProjectPhase::Tm, 'Proyek tidak sedang di tahap TM.');

            $missing = collect($this->requirements($project))->reject(fn (array $row) => $row['done'])->pluck('label');

            if ($missing->isNotEmpty()) {
                throw ValidationException::withMessages(['tm' => 'TM belum bisa diselesaikan — belum lengkap: '.$missing->implode(', ').'.']);
            }

            $project->technicalMeeting()->update(['completed_by' => $actor->id, 'completed_at' => now()]);
            $this->phaseService->advance($project, ProjectPhase::Tm, ProjectPhase::Persiapan, $actor);

            $this->notificationService->notifyMany(
                collect([$project->supervisor, $project->assistantPm])->filter(fn (?User $user) => $user && $user->id !== $actor->id),
                NotificationType::TechnicalMeetingCompleted,
                'TM Selesai — Lengkapi Syarat Produksi',
                "Technical Meeting proyek \"{$project->name}\" selesai. Pengawas: lengkapi bahan, upah & daily target, lalu ajukan DP pengawas.",
                ['project_id' => $project->id],
            );

            return $project;
        });
    }

    /** Material lines that count as "bahan yang digunakan": planned from the catalog or approved requests. */
    public function plannedMaterials(Project $project): Builder
    {
        return $project->projectMaterials()->getQuery()
            ->where(fn (Builder $q) => $q->whereNull('request_status')->orWhere('request_status', MaterialRequestStatus::Disetujui->value));
    }

    private function snapshot(TechnicalMeeting $meeting, Project $project): array
    {
        $fee = $project->supervisorFee()->first();

        return [
            'meeting_date' => $meeting->meeting_date?->toDateString(),
            'notes' => $meeting->notes,
            'attendees' => $meeting->attendees->map(fn ($row) => $row->label)->values()->all(),
            'fee_total' => $fee ? number_format((float) $fee->total_amount, 2, '.', '') : null,
            'dp_percent' => $fee ? number_format((float) $fee->dp_percent, 2, '.', '') : null,
            'end_date' => $project->end_date?->toDateString(),
        ];
    }
}
