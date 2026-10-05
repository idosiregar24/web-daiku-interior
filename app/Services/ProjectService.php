<?php

namespace App\Services;

use App\Enums\LeadStatus;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\QuotationStatus;
use App\Enums\TerminStatus;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\ProjectOpening;
use App\Models\Termin;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectService
{
    /**
     * Manual status moves (Sprint 9 decision #1). COMPLETED is never
     * reachable by hand — only completeIfFinished() sets it, once every
     * milestone passed QA and no material leftover remains — and
     * CANCELLED is terminal.
     */
    private const MANUAL_TRANSITIONS = [
        'ACTIVE' => ['ON_HOLD', 'CANCELLED'],
        'ON_HOLD' => ['ACTIVE', 'CANCELLED'],
    ];

    public function __construct(
        private NotificationService $notificationService,
        private AuditLogService $auditLogService,
        private TerminService $terminService,
    ) {}

    /**
     * Sprint 12 decision #19 — the CEO's "Buka Proyek": in one transaction
     * the project (RAB Fix = the approved quotation, contract value = its
     * total, PM, optional Asisten PM, start date) and its termins copied
     * from the payment scheme the client approved. The only way a project
     * is created since Sprint 12 Sub 7 (no manual project or termin route).
     *
     * @param  array{name: string, pm_id: int|string, assistant_pm_id?: int|string|null, start_date: string}  $data
     */
    public function openFromQuotation(ProjectOpening $opening, array $data, User $actor): Project
    {
        return DB::transaction(function () use ($opening, $data, $actor) {
            $opening = ProjectOpening::whereKey($opening->getKey())->lockForUpdate()->firstOrFail();

            if ($opening->status !== ProjectOpening::STATUS_WAITING) {
                throw ValidationException::withMessages(['opening' => 'Proyek ini sudah dibuka.']);
            }

            $quotation = $opening->quotation()->with('paymentTerms')->firstOrFail();
            $lead = $opening->lead()->firstOrFail();

            $project = $this->createFromLead($lead->setRelation('quotation', $quotation), [
                'name' => $data['name'],
                'pm_id' => $data['pm_id'],
                'assistant_pm_id' => $data['assistant_pm_id'] ?? null,
                'start_date' => $data['start_date'],
                'contract_value' => $quotation->total_amount,
                'quotation_id' => $quotation->id,
            ]);

            $termins = $this->terminService->createFromPaymentTerms($project, $quotation);

            $opening->update([
                'status' => ProjectOpening::STATUS_OPENED,
                'opened_by' => $actor->id,
                'opened_at' => now(),
                'project_id' => $project->id,
            ]);

            $this->auditLogService->record('project.opened', $project, null, [
                'quotation_id' => $quotation->id,
                'quotation_version' => $quotation->version,
                'pm_id' => $project->pm_id,
                'assistant_pm_id' => $project->assistant_pm_id,
                'start_date' => $data['start_date'],
                'contract_value' => $project->contract_value,
                'termins' => $termins->map(fn ($termin) => $termin->only(['termin_number', 'percentage', 'amount', 'trigger', 'scheduled_date']))->all(),
            ], $actor);

            return $project;
        });
    }

    /**
     * PRD §4.4: "Project hanya bisa dibuat dari Lead yang berstatus DEAL"
     * — the building block of openFromQuotation() (Sprint 12: no route of
     * its own any more). The "one project per lead" / status-eligibility
     * rules live here.
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
        // APPROVED" — the client's approval on the link (Sprint 12 Sub 5).
        if ($lead->quotation?->status !== QuotationStatus::ClientApproved) {
            throw ValidationException::withMessages([
                'lead_id' => 'Proyek hanya bisa dibuat setelah RAB Proyek lead ini disetujui klien.',
            ]);
        }

        $project = Project::create([
            'lead_id' => $lead->id,
            'quotation_id' => $data['quotation_id'] ?? null,
            'name' => $data['name'],
            'pm_id' => $data['pm_id'],
            'assistant_pm_id' => $data['assistant_pm_id'] ?? null,
            'start_date' => $data['start_date'],
            'contract_value' => $data['contract_value'],
        ]);

        // PRD §4.9 "Deal dikonfirmasi → PM, CEO, Finance, Logistics" — the
        // project's own PM (and Asisten PM) plus the divisions that act on
        // a new project (termins, material planning) and the lead's
        // Marketing, who invoices the termins (Sprint 12 #20).
        $this->notificationService->notifyMany(
            User::role(['CEO', 'FINANCE', 'LOGISTICS'])->where('is_active', true)->get()
                ->push($project->pm, $project->assistantPm, $lead->assignee)
                ->filter()
                ->unique('id'),
            'project_opened',
            'Proyek Dibuka',
            "Proyek \"{$project->name}\" untuk \"{$lead->client_name}\" dibuka dengan PM {$project->pm->name}.",
            ['project_id' => $project->id, 'lead_id' => $lead->id],
        );

        // Reloaded so DB defaults (status ACTIVE) are on the returned model.
        return $project->fresh();
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
     * CSV Sprint 6 "Project selesai flow: semua milestone COMPLETED →
     * project COMPLETED", plus Sprint 11 decision #8: not while a material
     * line still has an unsettled leftover (return / waste / hand over).
     * Called when the last milestone passes QA (QaFormService) and again
     * whenever a leftover gets settled (ProjectMaterialService), so the
     * project finishes the moment both conditions hold. A project with no
     * milestones never auto-completes. Returns whether it completed.
     *
     * $notifyWhenBlocked: tell the PM and Logistics which items are
     * holding the project up — only on the QA approval that finished the
     * milestones, not on every partial settlement after it.
     */
    public function completeIfFinished(Project $project, bool $notifyWhenBlocked = false): bool
    {
        if ($project->isClosed()) {
            return false;
        }

        $milestones = $project->milestones()->get(['id', 'status']);

        if ($milestones->isEmpty() || $milestones->contains(fn (Milestone $m) => $m->status !== MilestoneStatus::Completed)) {
            return false;
        }

        $leftovers = $this->materialLeftovers($project);
        // Sub 4: an undecided material request blocks completion too.
        $pendingRequests = $project->projectMaterials()->pendingRequest()->with('material:id,name')->get();

        if ($leftovers->isNotEmpty() || $pendingRequests->isNotEmpty()) {
            if ($notifyWhenBlocked) {
                $reasons = array_filter([
                    $leftovers->isNotEmpty()
                        ? 'masih ada sisa material: '.$this->describeLeftovers($leftovers).' (bereskan lewat retur / susut / serahkan ke klien)'
                        : null,
                    $pendingRequests->isNotEmpty()
                        ? 'masih ada pengajuan barang yang belum diputuskan: '.$pendingRequests->pluck('display_name')->implode(', ')
                        : null,
                ]);

                $this->notificationService->notifyMany(
                    User::role('LOGISTICS')->where('is_active', true)->get()->push($project->pm)->filter(),
                    'project_material_leftover',
                    'Proyek Tertahan: Material',
                    "Semua milestone proyek \"{$project->name}\" lolos QA, tetapi proyek belum bisa COMPLETED karena "
                        .implode('; ', $reasons).'. Selesaikan di tab Material.',
                    ['project_id' => $project->id],
                );
            }

            return false;
        }

        $project->update([
            'status' => ProjectStatus::Completed->value,
            'end_date' => now('Asia/Jakarta')->toDateString(),
        ]);

        // Production is done — the lead's design becomes DONE_PRODUKSI,
        // which also freezes its delay count (Sprint 9 decision #4).
        app(DesignService::class)->syncWithPipeline($project->lead_id, DesignService::EVENT_PROJECT_COMPLETED);

        $this->notificationService->notifyMany(
            User::role('CEO')->where('is_active', true)->get()->push($project->pm)->filter(),
            'project_completed',
            'Proyek Selesai',
            "Semua milestone proyek \"{$project->name}\" lolos QA — proyek ditandai COMPLETED.",
            ['project_id' => $project->id],
        );

        return true;
    }

    /**
     * Material lines whose leftover isn't settled yet (Sprint 11 decision #8).
     *
     * @return Collection<int, ProjectMaterial>
     */
    public function materialLeftovers(Project $project): Collection
    {
        return $project->projectMaterials()->withLeftover()->with(['material:id,name,unit_id', 'unit:id,code'])->get();
    }

    /** "Triplek 17mm (2 lbr), Lem Kayu (0,5 kg)" */
    public function describeLeftovers(Collection $leftovers): string
    {
        return $leftovers
            ->map(fn (ProjectMaterial $line) => "{$line->display_name} ({$line->quantityLabel($line->leftover)})")
            ->implode(', ');
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
