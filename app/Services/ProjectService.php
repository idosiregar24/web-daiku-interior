<?php

namespace App\Services;

use App\Enums\LeadStatus;
use App\Enums\ProjectStatus;
use App\Enums\QuotationStatus;
use App\Enums\TerminStatus;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Termin;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectService
{
    /**
     * Manual status moves (Sprint 9 decision #1). COMPLETED is never
     * reachable by hand — only QaFormService sets it, once every milestone
     * passed QA — and CANCELLED is terminal.
     */
    private const MANUAL_TRANSITIONS = [
        'ACTIVE' => ['ON_HOLD', 'CANCELLED'],
        'ON_HOLD' => ['ACTIVE', 'CANCELLED'],
    ];

    public function __construct(
        private NotificationService $notificationService,
        private AuditLogService $auditLogService,
    ) {}

    /**
     * PRD §4.4: "Project hanya bisa dibuat dari Lead yang berstatus DEAL".
     * Two callers: `LeadController::confirmDeal()` (Marketing closing a
     * deal — bundles the Lead status change via `LeadService::confirmDeal()`)
     * and `ProjectController::store()` (PM creating directly for a lead
     * that's already DEAL_DESAIN/CLOSING). Both funnel through here so the
     * "one project per lead" / status-eligibility rules live in one place.
     */
    public function createFromLead(Lead $lead, array $data): Project
    {
        if (! in_array($lead->status, [LeadStatus::DealDesain, LeadStatus::Closing], true)) {
            throw ValidationException::withMessages([
                'lead_id' => 'Proyek hanya bisa dibuat dari lead berstatus DEAL_DESAIN atau CLOSING.',
            ]);
        }

        if ($lead->project()->exists()) {
            throw ValidationException::withMessages([
                'lead_id' => 'Lead ini sudah punya proyek.',
            ]);
        }

        // PRD §4.3 "Konversi ke Project hanya bisa dilakukan setelah status
        // APPROVED" — enforced here, not only in LeadService::confirmDeal(),
        // so PM's direct projects.store path can't skip the quotation.
        if ($lead->quotation?->status !== QuotationStatus::Approved) {
            throw ValidationException::withMessages([
                'lead_id' => 'Proyek hanya bisa dibuat setelah quotation lead ini berstatus APPROVED.',
            ]);
        }

        return Project::create([
            'lead_id' => $lead->id,
            'name' => $data['name'],
            'pm_id' => $data['pm_id'],
            'start_date' => $data['start_date'],
            'contract_value' => $data['contract_value'],
        ]);
    }

    /**
     * "Edit Proyek" (Sprint 9 decision #1). Who may call it at all is
     * ProjectPolicy::update() (CEO any project, PM their own); the rules
     * that need the stored row live here:
     *
     * - COMPLETED/CANCELLED projects are read-only.
     * - Only the CEO re-assigns the PM (PRD §4.4 "PM di-assign oleh CEO");
     *   the new and the previous PM are both notified.
     * - Status: ACTIVE ↔ ON_HOLD, either → CANCELLED with a reason.
     * - The contract value is fixed once any termin received money; before
     *   that, changing it re-derives every termin's amount from its
     *   percentage (sisa_piutang is DB-generated and follows).
     *
     * Works on a locked re-read, so two concurrent edits — or an edit
     * racing a termin payment (TerminService::recordPayment() locks the
     * termin row too) — can't both pass these checks against stale state.
     *
     * @param  array{name: string, start_date: string, end_date?: ?string, contract_value: numeric-string|int|float, status: string, note?: ?string, pm_id?: int|string}  $data
     */
    public function update(Project $project, array $data, User $actor): Project
    {
        return DB::transaction(function () use ($project, $data, $actor) {
            /** @var Project $locked */
            $locked = Project::query()->lockForUpdate()->findOrFail($project->id);

            if ($locked->isClosed()) {
                throw ValidationException::withMessages([
                    'status' => "Proyek ini sudah {$locked->status->value} — datanya final dan tidak bisa diubah lagi.",
                ]);
            }

            $newPmId = (int) ($data['pm_id'] ?? $locked->pm_id);

            if ($newPmId !== (int) $locked->pm_id && ! $actor->hasAnyRole(['CEO', 'SUPERADMIN'])) {
                throw ValidationException::withMessages([
                    'pm_id' => 'Project Manager hanya bisa diganti oleh CEO.',
                ]);
            }

            $newStatus = ProjectStatus::from($data['status']);
            $this->ensureManualTransition($locked->status, $newStatus, $data['note'] ?? null);

            $recalculatedTermins = $this->toCents($data['contract_value']) !== $this->toCents($locked->contract_value)
                ? $this->recalculateTermins($locked, $data['contract_value'])
                : 0;

            $previousPm = $locked->pm()->first(['id', 'name']);
            $before = $this->snapshot($locked);

            $locked->update([
                'name' => $data['name'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'contract_value' => $data['contract_value'],
                'status' => $newStatus->value,
                'pm_id' => $newPmId,
            ]);

            $after = $this->snapshot($locked);
            $changed = array_keys(array_diff_assoc($after, $before));

            if ($changed === []) {
                return $locked;
            }

            $old = array_intersect_key($before, array_flip($changed));
            $new = array_intersect_key($after, array_flip($changed));
            $newPm = null;

            if (in_array('pm_id', $changed, true)) {
                $newPm = $locked->pm()->first(['id', 'name']);
                $old['pm'] = $previousPm?->name;
                $new['pm'] = $newPm?->name;
            }

            if (in_array('status', $changed, true) && filled($data['note'] ?? null)) {
                $new['note'] = $data['note'];
            }

            if ($recalculatedTermins > 0) {
                $new['termins_recalculated'] = $recalculatedTermins;
            }

            $this->auditLogService->record('project.updated', $locked, $old, $new, $actor);

            if ($newPm) {
                $this->notifyPmChange($locked, $newPm, $previousPm, $actor);
            }

            return $locked;
        });
    }

    /**
     * The note that came with the project's current ON_HOLD/CANCELLED
     * status — the reason lives in its `project.updated` audit row (there
     * is no column for it), so the detail page can say why.
     *
     * @return array{note: string|null, by: string|null, at: string}|null
     */
    public function statusNote(Project $project): ?array
    {
        if (! in_array($project->status, [ProjectStatus::OnHold, ProjectStatus::Cancelled], true)) {
            return null;
        }

        $log = AuditLog::query()
            ->with('user:id,name')
            ->where('action', 'project.updated')
            ->where('model_type', class_basename(Project::class))
            ->where('model_id', $project->id)
            ->where('new_values->status', $project->status->value)
            ->latest('id')
            ->first();

        if (! $log) {
            return null;
        }

        return [
            'note' => $log->new_values['note'] ?? null,
            'by' => $log->user?->name,
            'at' => $log->created_at->toIso8601String(),
        ];
    }

    private function ensureManualTransition(ProjectStatus $from, ProjectStatus $to, ?string $note): void
    {
        if ($from === $to) {
            return;
        }

        if (! in_array($to->value, self::MANUAL_TRANSITIONS[$from->value] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => $to === ProjectStatus::Completed
                    ? 'Status COMPLETED hanya diset otomatis setelah semua milestone lolos QA.'
                    : "Status proyek tidak bisa diubah dari {$from->value} ke {$to->value}.",
            ]);
        }

        if ($to === ProjectStatus::Cancelled && blank($note)) {
            throw ValidationException::withMessages([
                'note' => 'Alasan pembatalan proyek wajib diisi.',
            ]);
        }
    }

    /**
     * termin.amount = contract_value × percentage / 100, same formula as
     * TerminService::create(). Refused once any termin received a DP or
     * pelunasan (or is PAID) — the rows are locked first, so a payment
     * can't slip in between the check and the rewrite. Same condition as
     * Project::hasTerminPayments(), evaluated on the locked rows.
     */
    private function recalculateTermins(Project $project, mixed $contractValue): int
    {
        $termins = Termin::query()->where('project_id', $project->id)->lockForUpdate()->get();

        $hasPayment = $termins->contains(fn (Termin $termin) => $termin->status === TerminStatus::Paid
            || $this->toCents($termin->dp_amount) > 0
            || $this->toCents($termin->pelunasan) > 0);

        if ($hasPayment) {
            throw ValidationException::withMessages([
                'contract_value' => 'Nilai kontrak tidak bisa diubah — proyek ini sudah menerima pembayaran termin (DP/pelunasan).',
            ]);
        }

        foreach ($termins as $termin) {
            $termin->update(['amount' => round((float) $contractValue * $termin->percentage / 100, 2)]);
        }

        return $termins->count();
    }

    /** PRD §4.9-style heads-up to both sides of a PM hand-over. */
    private function notifyPmChange(Project $project, User $newPm, ?User $previousPm, User $actor): void
    {
        $this->notificationService->notify(
            $newPm,
            'project_pm_assigned',
            'Ditunjuk sebagai Project Manager',
            "Anda ditunjuk sebagai Project Manager proyek \"{$project->name}\" oleh {$actor->name}.",
            ['project_id' => $project->id],
        );

        if ($previousPm) {
            $this->notificationService->notify(
                $previousPm,
                'project_pm_unassigned',
                'Pergantian Project Manager',
                "Proyek \"{$project->name}\" kini dipegang {$newPm->name} — Anda bukan lagi Project Manager proyek ini.",
                ['project_id' => $project->id],
            );
        }
    }

    /** The editable fields, normalized so a no-op save compares equal. */
    private function snapshot(Project $project): array
    {
        return [
            'name' => $project->name,
            'pm_id' => (int) $project->pm_id,
            'status' => $project->status->value,
            'start_date' => $project->start_date?->toDateString(),
            'end_date' => $project->end_date?->toDateString(),
            'contract_value' => number_format((float) $project->contract_value, 2, '.', ''),
        ];
    }

    /** Money math in integer cents — same approach as TerminService. */
    private function toCents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }
}
