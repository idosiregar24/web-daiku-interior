<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\ProjectPhase;
use App\Models\Project;
use App\Models\SupervisorFee;
use App\Models\TechnicalMeeting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

            if (! $project->supervisors()->exists()) {
                throw ValidationException::withMessages(['fee_total' => 'Tentukan Pengawas (QA/QC) proyek lewat Ubah Proyek dulu.']);
            }

            $meeting = TechnicalMeeting::firstOrNew(['project_id' => $project->id]);
            $before = $meeting->exists ? $this->snapshot($meeting, $project) : null;
            $previousAt = $meeting->startsAt()?->toDateTimeString();
            // Everyone a re-save already reached (attendees, Pengawas, PM) — only people added later are invited then.
            $previousInvitees = $meeting->exists ? $this->invitees($project, $meeting)->pluck('id')->all() : [];

            // Sprint 34 — a TM saved without a mode keeps its own (older forms schedule).
            $mode = $data['mode'] ?? $meeting->mode ?? TechnicalMeeting::MODE_SCHEDULED;
            $meeting->fill([
                'meeting_date' => $data['meeting_date'],
                'meeting_time' => array_key_exists('meeting_time', $data) ? ($data['meeting_time'] ?: null) : $meeting->meeting_time,
                'mode' => $mode,
                'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
                'recorded_by' => $actor->id,
            ]);
            $moved = $previousAt !== null && $meeting->startsAt()?->toDateTimeString() !== $previousAt;

            if ($moved) {
                // A new date / time: the reminders count again.
                $meeting->reminded_day_before_at = null;
                $meeting->reminded_soon_at = null;
            }

            $meeting->save();

            $meeting->attendees()->delete();
            collect($data['attendees'] ?? [])
                ->map(fn (array $row) => filled($row['user_id'] ?? null)
                    ? ['user_id' => (int) $row['user_id'], 'name' => null]
                    : ['user_id' => null, 'name' => filled($row['name'] ?? null) ? trim($row['name']) : null])
                ->filter(fn (array $row) => $row['user_id'] !== null || $row['name'] !== null)
                ->unique(fn (array $row) => $row['user_id'] ? "u{$row['user_id']}" : 'n'.mb_strtolower($row['name']))
                ->each(fn (array $row) => $meeting->attendees()->create($row));

            // Sprint 29 Sub 08 — with work items the total is Σ upah per item; the TM keeps only the DP %.
            if ($project->workItems()->exists()) {
                $fee = app(WorkItemService::class)->syncFee($project, $actor);

                if ($fee && filled($data['dp_percent'] ?? null)) {
                    $fee->update(['dp_percent' => round((float) $data['dp_percent'], 2)]);
                }
            } elseif (filled($data['fee_total'] ?? null)) {
                // Empty only while no fee exists yet (SaveTechnicalMeetingRequest) — the TM's schedule saved first.
                SupervisorFee::updateOrCreate(['project_id' => $project->id], [
                    'supervisor_id' => $project->supervisors()->value('users.id'),
                    'total_amount' => round((float) $data['fee_total'], 2),
                    'dp_percent' => round((float) $data['dp_percent'], 2),
                    'set_by' => $actor->id,
                ]);
            }

            if (array_key_exists('end_date', $data)) {
                $project->update(['end_date' => filled($data['end_date']) ? $data['end_date'] : null]);
            }

            $meeting->load('attendees.user:id,name');
            $this->auditLogService->record('technical_meeting.saved', $project, $before, $this->snapshot($meeting, $project->fresh()), $actor);

            $this->invite($project, $meeting, $moved, $previousInvitees, $actor);

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

        // Sprint 29 Sub 03 (D1) — the PM's part only. Bahan & upah are the
        // Pengawas's, filled after the TM up to Start Produksi (dpRequirements).
        return [
            ['key' => 'notes', 'label' => 'Tanggal & notulen TM', 'done' => $meeting !== null && $meeting->meeting_date !== null && filled($meeting->notes)],
            ['key' => 'attendees', 'label' => 'Minimal satu peserta', 'done' => (int) ($meeting?->attendees_count ?? 0) > 0],
            ['key' => 'timeline', 'label' => 'Timeline (durasi) disusun PM', 'done' => $project->milestones()->exists()],
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
                $project->supervisors()->get()->push($project->assistantPm)->filter(fn (?User $user) => $user && $user->id !== $actor->id)->unique('id'),
                NotificationType::TechnicalMeetingCompleted,
                'TM Selesai — Lengkapi Syarat Produksi',
                "Technical Meeting proyek \"{$project->name}\" selesai. Pengawas: lengkapi bahan, upah & daily target, lalu ajukan DP upah tukang.",
                ['project_id' => $project->id],
            );

            return $project;
        });
    }

    /**
     * Sprint 34 — a scheduled TM (JADWAL) still ahead invites its people:
     * everyone on the first save and when the date / time moves; on a plain
     * re-save only the people just added. A TM held right away (LANGSUNG) or
     * already past invites nobody.
     *
     * @param  list<int>  $previousInvitees
     */
    private function invite(Project $project, TechnicalMeeting $meeting, bool $moved, array $previousInvitees, User $actor): void
    {
        if ($meeting->mode !== TechnicalMeeting::MODE_SCHEDULED || $meeting->isCompleted() || ! $meeting->startsAt()?->isFuture()) {
            return;
        }

        $everyone = $this->invitees($project, $meeting)->reject(fn (User $user) => $user->id === $actor->id);
        $firstTime = $meeting->invited_at === null;
        $recipients = $firstTime || $moved ? $everyone : $everyone->reject(fn (User $user) => in_array($user->id, $previousInvitees, true));

        if ($recipients->isEmpty()) {
            return;
        }

        $this->notificationService->notifyMany(
            $recipients->values(),
            NotificationType::TechnicalMeetingScheduled,
            $moved ? 'Jadwal TM Diubah' : 'Undangan Technical Meeting',
            "Technical Meeting proyek \"{$project->name}\" ".($moved ? 'dipindah ke' : 'dijadwalkan')." {$meeting->scheduleLabel()}. Anda diundang sebagai peserta. Pengawas: rencanakan bahan yang digunakan sebelum TM.",
            ['project_id' => $project->id],
        );

        $meeting->update(['invited_at' => now()]);
    }

    /**
     * Who a scheduled TM notifies: the attendees with an account, the
     * project's Pengawas and its PM.
     *
     * @return Collection<int, User>
     */
    private function invitees(Project $project, TechnicalMeeting $meeting): Collection
    {
        return $meeting->attendees()->with('user')->get()->pluck('user')
            ->concat($project->supervisors()->get())
            ->push($project->pm)
            ->filter(fn (?User $user) => $user && $user->is_active)
            ->unique('id')
            ->values();
    }

    /**
     * Sprint 34 — reminders of a scheduled TM: "besok" from 24 hours before
     * and "2 jam lagi" from 2 hours before, each once (stamped), and only if
     * the invitation went out before that window (a TM set up for this
     * afternoon doesn't remind right after its invitation). Run by the
     * scheduler every 15 minutes.
     *
     * @return int the reminders sent
     */
    public function sendReminders(?Carbon $now = null): int
    {
        $now ??= now('Asia/Jakarta');
        $sent = 0;

        TechnicalMeeting::query()
            ->where('mode', TechnicalMeeting::MODE_SCHEDULED)
            ->whereNull('completed_at')
            ->whereNotNull('invited_at')
            ->whereBetween('meeting_date', [$now->copy()->toDateString(), $now->copy()->addDays(2)->toDateString()])
            ->whereHas('project', fn (Builder $project) => $project->active()->where('phase', ProjectPhase::Tm->value))
            ->with('project')
            ->get()
            ->each(function (TechnicalMeeting $meeting) use ($now, &$sent) {
                $at = $meeting->startsAt();

                if (! $at || $now->gte($at)) {
                    return;
                }

                $soon = $now->gte($at->copy()->subHours(2));
                $stamp = $soon ? 'reminded_soon_at' : 'reminded_day_before_at';
                $windowStart = $soon ? $at->copy()->subHours(2) : $at->copy()->subDay();

                if ($meeting->{$stamp} !== null || $now->lt($windowStart) || $meeting->invited_at->gte($windowStart)) {
                    return;
                }

                $when = $soon
                    ? 'hari ini pukul '.str_replace(':', '.', $at->format('H:i'))
                    : ($at->isSameDay($now->copy()->addDay()) ? 'besok, ' : '').$meeting->scheduleLabel();

                $this->notificationService->notifyMany(
                    $this->invitees($meeting->project, $meeting),
                    NotificationType::TechnicalMeetingReminder,
                    $soon ? 'TM Sebentar Lagi' : 'Pengingat Technical Meeting',
                    "Technical Meeting proyek \"{$meeting->project->name}\" {$when}.",
                    ['project_id' => $meeting->project_id],
                );
                $meeting->update([$stamp => $now]);
                $sent++;
            });

        return $sent;
    }

    /**
     * Material lines that count as "bahan yang digunakan": planned from the
     * catalog, approved requests and the Pengawas's Rencana Bahan (Sprint 29
     * Sub 11 — rejected plan lines only with `$withRejected`).
     */
    public function plannedMaterials(Project $project, bool $withRejected = false): Builder
    {
        return $project->projectMaterials()->getQuery()->planned($withRejected);
    }

    private function snapshot(TechnicalMeeting $meeting, Project $project): array
    {
        $fee = $project->supervisorFee()->first();

        return [
            'meeting_date' => $meeting->meeting_date?->toDateString(),
            'meeting_time' => $meeting->meeting_time ? substr($meeting->meeting_time, 0, 5) : null,
            'mode' => $meeting->mode,
            'notes' => $meeting->notes,
            'attendees' => $meeting->attendees->map(fn ($row) => $row->label)->values()->all(),
            'fee_total' => $fee ? number_format((float) $fee->total_amount, 2, '.', '') : null,
            'dp_percent' => $fee ? number_format((float) $fee->dp_percent, 2, '.', '') : null,
            'end_date' => $project->end_date?->toDateString(),
        ];
    }
}
