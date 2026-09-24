<?php

namespace App\Services;

use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\QaStatus;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\QaForm;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PRD §4.6 + §6.3. QaForm is only ever created by
 * MilestoneService::markDone() (system-triggered, never a controller
 * action per PRD's own business rule) and reviewed here.
 */
class QaFormService
{
    /**
     * PRD: "isian item checklist dikonfigurasi per tipe milestone" — no
     * milestone "type" taxonomy exists in this codebase yet (Milestone
     * only has name/target_date/status/order), so this is one fixed
     * generic checklist rather than an invented per-type config system
     * nothing else asked for.
     */
    private const DEFAULT_CHECKLIST = [
        ['label' => 'Hasil pekerjaan sesuai spesifikasi/desain', 'passed' => false, 'note' => null],
        ['label' => 'Kerapian dan kebersihan area kerja', 'passed' => false, 'note' => null],
        ['label' => 'Tidak ada kerusakan material/alat', 'passed' => false, 'note' => null],
        ['label' => 'Dokumentasi foto sudah lengkap', 'passed' => false, 'note' => null],
    ];

    public function __construct(
        private NotificationService $notificationService,
        private AuditLogService $auditLogService,
    ) {}

    public function createForMilestone(Milestone $milestone): QaForm
    {
        $qaForm = QaForm::create([
            'project_id' => $milestone->project_id,
            'milestone_id' => $milestone->id,
            'status' => QaStatus::Pending->value,
            'checklist_data' => self::DEFAULT_CHECKLIST,
        ]);

        $this->notifyQaTeam($qaForm, $milestone, 'siap direview');

        return $qaForm;
    }

    /**
     * PM fixed a rejected milestone and marked it done again — the same
     * form (unique per milestone) goes back to PENDING for another pass.
     * `rejection_count` is kept: it's what "reject 2x berturut-turut"
     * counts.
     */
    public function resubmit(QaForm $qaForm): QaForm
    {
        if ($qaForm->status === QaStatus::Approved) {
            return $qaForm;
        }

        $qaForm->update(['status' => QaStatus::Pending->value]);
        $this->notifyQaTeam($qaForm, $qaForm->milestone, 'sudah diperbaiki PM dan siap direview ulang');

        return $qaForm->fresh();
    }

    /** PRD §4.9 "Milestone selesai → QA Form dibuat → Tim QA". */
    private function notifyQaTeam(QaForm $qaForm, Milestone $milestone, string $state): void
    {
        $this->notificationService->notifyRoles(
            ['QA'],
            'qa_form_created',
            'QA Form Baru',
            "Milestone \"{$milestone->name}\" ({$milestone->project->name}) {$state}.",
            ['qa_form_id' => $qaForm->id, 'project_id' => $milestone->project_id],
        );
    }

    /**
     * @param  array<int, array{label: string, passed: bool, note?: ?string}>  $checklistData
     */
    public function review(QaForm $qaForm, string $decision, array $checklistData, ?string $notes, User $actor): QaForm
    {
        if ($qaForm->status === QaStatus::Approved) {
            throw ValidationException::withMessages([
                'status' => 'QA Form yang sudah APPROVED tidak bisa diubah kembali.',
            ]);
        }

        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw ValidationException::withMessages(['decision' => 'Keputusan tidak valid.']);
        }

        if ($decision === 'reject' && ! $notes) {
            throw ValidationException::withMessages([
                'notes' => 'Catatan perbaikan wajib diisi saat reject.',
            ]);
        }

        return DB::transaction(function () use ($qaForm, $decision, $checklistData, $notes, $actor) {
            $milestone = $qaForm->milestone()->with('project.pm')->firstOrFail();
            $before = ['status' => $qaForm->status, 'rejection_count' => $qaForm->rejection_count];

            if ($decision === 'approve') {
                $qaForm->update([
                    'status' => QaStatus::Approved->value,
                    'reviewer_id' => $actor->id,
                    'checklist_data' => $checklistData,
                    'notes' => $notes,
                    'reviewed_at' => now(),
                ]);

                $milestone->update(['status' => MilestoneStatus::Completed->value]);
                $this->advanceNextMilestone($milestone);
                $this->completeProjectIfDone($milestone->project);
                $this->notifyPm($milestone, $qaForm, 'QA menyetujui milestone "'.$milestone->name.'".', 'qa_approved');
            } else {
                $qaForm->update([
                    'status' => QaStatus::Rejected->value,
                    'reviewer_id' => $actor->id,
                    'checklist_data' => $checklistData,
                    'notes' => $notes,
                    'rejection_count' => $qaForm->rejection_count + 1,
                    'reviewed_at' => now(),
                ]);

                // Unblock the PM to keep fixing — see MilestoneService::markDone()'s docblock.
                $milestone->update(['status' => MilestoneStatus::InProgress->value]);
                $this->notifyPm($milestone, $qaForm, 'QA menolak milestone "'.$milestone->name.'": '.$notes, 'qa_rejected');

                if ($qaForm->rejection_count >= 2) {
                    $this->notificationService->notifyRoles(
                        ['CEO'],
                        'qa_rejected_twice',
                        'QA Reject Berulang',
                        'Milestone "'.$milestone->name.'" ('.$milestone->project->name.') ditolak QA '.$qaForm->rejection_count.'x berturut-turut.',
                        ['qa_form_id' => $qaForm->id, 'milestone_id' => $milestone->id, 'project_id' => $milestone->project_id],
                    );
                }
            }

            // PRD §9.4 "QA decision" — audit trail.
            $this->auditLogService->record(
                $decision === 'approve' ? 'qa.approved' : 'qa.rejected',
                $qaForm,
                $before,
                [
                    'status' => $qaForm->status,
                    'rejection_count' => $qaForm->rejection_count,
                    'notes' => $notes,
                    'milestone' => $milestone->name,
                    'checklist_passed' => collect($checklistData)->where('passed', true)->count().'/'.count($checklistData),
                ],
                $actor,
            );

            return $qaForm->fresh();
        });
    }

    /** PRD §6.3 "Milestone berikutnya IN_PROGRESS" once the current one is COMPLETED. */
    private function advanceNextMilestone(Milestone $milestone): void
    {
        $next = $milestone->project->milestones()
            ->where('order', '>', $milestone->order)
            ->orderBy('order')
            ->first();

        if ($next && $next->status === MilestoneStatus::Pending) {
            $next->update(['status' => MilestoneStatus::InProgress->value]);
        }
    }

    /**
     * CSV Sprint 6 "Project selesai flow: semua milestone COMPLETED →
     * project COMPLETED". Runs inside the approving review's transaction,
     * so a project can only finish through QA — never by a PM editing a
     * status field. A project with no milestones never auto-completes.
     */
    private function completeProjectIfDone(Project $project): void
    {
        $milestones = $project->milestones()->get(['id', 'status']);

        if ($milestones->isEmpty() || $milestones->contains(fn (Milestone $m) => $m->status !== MilestoneStatus::Completed)) {
            return;
        }

        $project->update([
            'status' => ProjectStatus::Completed->value,
            'end_date' => now('Asia/Jakarta')->toDateString(),
        ]);

        $this->notificationService->notifyMany(
            User::role('CEO')->where('is_active', true)->get()->push($project->pm),
            'project_completed',
            'Proyek Selesai',
            "Semua milestone proyek \"{$project->name}\" lolos QA — proyek ditandai COMPLETED.",
            ['project_id' => $project->id],
        );
    }

    /** PRD §4.6 "PM mendapat notifikasi langsung ketika QA approve/reject". */
    private function notifyPm(Milestone $milestone, QaForm $qaForm, string $message, string $type): void
    {
        if (! $milestone->project->pm) {
            return;
        }

        $this->notificationService->notify(
            $milestone->project->pm,
            $type,
            'Keputusan QA',
            $message,
            ['qa_form_id' => $qaForm->id, 'milestone_id' => $milestone->id, 'project_id' => $milestone->project_id],
        );
    }
}
