<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\ProjectPhase;
use App\Enums\WorkingDrawingStatus;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkingDrawing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 24 D2 — "Gambar Kerja". A new project lands in the design team's
 * queue; a Kepala Desain assigns it (PIC, assistants, start + target days —
 * the same dialog as a design); the team saves the drawing links and
 * presses "Gambar Kerja Selesai". No client approval and no invoice: the
 * design team's drawing is final, and the project moves on to the TM.
 */
class WorkingDrawingService
{
    public function __construct(
        private NotificationService $notificationService,
        private AuditLogService $auditLogService,
        private ProjectPhaseService $phaseService,
    ) {}

    /** Called by "Buka Proyek" (ProjectService::openFromQuotation()), inside its transaction. */
    public function openForProject(Project $project): WorkingDrawing
    {
        $drawing = WorkingDrawing::create([
            'project_id' => $project->id,
            'status' => WorkingDrawingStatus::MenungguPenugasan->value,
        ]);

        $this->notificationService->notifyRoles(
            ['KEPALA_DESAIN'],
            NotificationType::WorkingDrawingToAssign,
            'Gambar Kerja Baru',
            "Proyek \"{$project->name}\" dibuka — tugaskan arsitek untuk gambar kerjanya.",
            ['working_drawing_id' => $drawing->id, 'project_id' => $project->id],
        );

        return $drawing;
    }

    /**
     * Kepala Desain picks the PIC (themself allowed), the assistants and the
     * timeline; redoable until the drawing is final.
     *
     * @param  array{pic_id: int|string, assistant_ids?: array<int, int|string>, start_date: string, target_hari: int|string}  $data
     */
    public function assign(WorkingDrawing $drawing, array $data, User $actor): WorkingDrawing
    {
        return DB::transaction(function () use ($drawing, $data, $actor) {
            $drawing = WorkingDrawing::lockForUpdate()->findOrFail($drawing->id);

            if ($drawing->status === WorkingDrawingStatus::Selesai) {
                throw ValidationException::withMessages(['pic_id' => 'Gambar kerja ini sudah selesai — tidak bisa ditugaskan ulang.']);
            }

            $picId = (int) $data['pic_id'];
            $assistantIds = collect($data['assistant_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->reject(fn (int $id) => $id === $picId)
                ->unique()
                ->values();
            $targetHari = (int) $data['target_hari'];
            $before = ['status' => $drawing->status->value, 'pic_id' => $drawing->pic_id, 'assistant_ids' => $drawing->staff()->pluck('users.id')->all()];

            $drawing->update([
                'pic_id' => $picId,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
                'start_date' => $data['start_date'],
                'target_hari' => $targetHari,
                'deadline' => Carbon::parse($data['start_date'])->addDays($targetHari)->toDateString(),
                'status' => WorkingDrawingStatus::Dikerjakan->value,
            ]);
            $drawing->staff()->sync($assistantIds->all());

            $this->auditLogService->record('working_drawing.assigned', $drawing, $before, [
                'status' => WorkingDrawingStatus::Dikerjakan->value,
                'pic_id' => $picId,
                'assistant_ids' => $assistantIds->all(),
                'start_date' => $data['start_date'],
                'target_hari' => $targetHari,
            ], $actor);

            $project = $drawing->project;
            $this->notificationService->notifyMany(
                User::whereIn('id', [...$assistantIds->all(), $picId])->where('id', '!=', $actor->id)->get(),
                NotificationType::WorkingDrawingAssigned,
                'Gambar Kerja Ditugaskan',
                "{$actor->name} menugaskan Anda pada gambar kerja proyek \"{$project->name}\" — deadline {$drawing->deadline->translatedFormat('d M Y')}.",
                ['working_drawing_id' => $drawing->id, 'project_id' => $project->id],
            );

            return $drawing->fresh();
        });
    }

    /**
     * The drawing links (Drive / PDF) and a short note — by the team, once
     * assigned and until it is final. A save that changes nothing records
     * nothing.
     *
     * @param  array{drawing_urls?: ?array<int, string>, note?: ?string}  $data
     */
    public function updateLinks(WorkingDrawing $drawing, array $data, User $actor): bool
    {
        return DB::transaction(function () use ($drawing, $data, $actor) {
            $drawing = WorkingDrawing::lockForUpdate()->findOrFail($drawing->id);

            if ($drawing->status !== WorkingDrawingStatus::Dikerjakan) {
                throw ValidationException::withMessages(['drawing_urls' => $drawing->status === WorkingDrawingStatus::Selesai
                    ? 'Gambar kerja sudah selesai — link-nya final.'
                    : 'Link baru bisa disimpan setelah Kepala Desain menugaskan arsitek.']);
            }

            $after = [
                'drawing_urls' => collect($data['drawing_urls'] ?? [])->map(fn ($url) => trim((string) $url))->filter()->values()->all(),
                'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
            ];
            $before = ['drawing_urls' => $drawing->drawing_urls ?? [], 'note' => $drawing->note];

            if ($before === $after) {
                return false;
            }

            $drawing->update([...$after, 'links_updated_by' => $actor->id, 'links_updated_at' => now()]);
            $this->auditLogService->record('working_drawing.links_updated', $drawing, $before, $after, $actor);

            return true;
        });
    }

    /**
     * "Gambar Kerja Selesai" (D2) — the PIC, an assistant or a Kepala Desain,
     * with at least one link saved. Final at once: the project moves to the
     * TM and its PM, Asisten PM and Pengawas are told.
     */
    public function complete(WorkingDrawing $drawing, User $actor): WorkingDrawing
    {
        return DB::transaction(function () use ($drawing, $actor) {
            $drawing = WorkingDrawing::lockForUpdate()->findOrFail($drawing->id);

            if ($drawing->status !== WorkingDrawingStatus::Dikerjakan) {
                throw ValidationException::withMessages(['status' => $drawing->status === WorkingDrawingStatus::Selesai
                    ? 'Gambar kerja ini sudah selesai.'
                    : 'Gambar kerja belum ditugaskan.']);
            }

            if (empty($drawing->drawing_urls)) {
                throw ValidationException::withMessages(['status' => 'Simpan minimal satu link gambar kerja dulu.']);
            }

            $drawing->update([
                'status' => WorkingDrawingStatus::Selesai->value,
                'completed_by' => $actor->id,
                'completed_at' => now(),
            ]);
            $this->auditLogService->record('working_drawing.completed', $drawing, ['status' => WorkingDrawingStatus::Dikerjakan->value], [
                'status' => WorkingDrawingStatus::Selesai->value,
                'drawing_urls' => $drawing->drawing_urls,
            ], $actor);

            $project = Project::lockForUpdate()->findOrFail($drawing->project_id);

            if ($project->currentPhase() === ProjectPhase::GambarKerja) {
                $this->phaseService->advance($project, ProjectPhase::GambarKerja, ProjectPhase::Tm, $actor);
            }

            $this->notificationService->notifyMany(
                $project->supervisors()->get()->push($project->pm, $project->assistantPm)->filter()->unique('id'),
                NotificationType::WorkingDrawingCompleted,
                'Gambar Kerja Selesai',
                "Gambar kerja proyek \"{$project->name}\" selesai ({$actor->name}) — jadwalkan Technical Meeting.",
                ['working_drawing_id' => $drawing->id, 'project_id' => $project->id],
            );

            return $drawing->fresh();
        });
    }
}
