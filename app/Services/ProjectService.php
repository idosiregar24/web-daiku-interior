<?php

namespace App\Services;

use App\Enums\LeadStatus;
use App\Enums\MilestoneStatus;
use App\Enums\NotificationType;
use App\Enums\ProjectPhase;
use App\Enums\ProjectStatus;
use App\Enums\QuotationStatus;
use App\Enums\TerminStatus;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\ProjectOpening;
use App\Models\Quotation;
use App\Models\Termin;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
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
     * Sprint 23 K14 — the people the "Buka Proyek" form picks from (the
     * pop-up, "Menunggu Dibuka", and — teamLeads/supervisors — Edit Proyek).
     *
     * @return array{projectManagers: SupportCollection, assistantPms: SupportCollection, teamLeads: SupportCollection, supervisors: SupportCollection}
     */
    public function openingFormOptions(): array
    {
        return [
            'projectManagers' => $this->activeHolders('PM'),
            'assistantPms' => $this->activeHolders('ASISTEN_PM'),
            ...$this->teamOptions(),
        ];
    }

    /**
     * Kepala Tim: every active account ("semua karyawan aktif", Q9 — a
     * user, so Sprint 24 can notify them; someone without an account is
     * typed as a name instead), without technical SUPERADMIN accounts.
     * Pengawas (QA/QC): active users with role QA.
     *
     * @return array{teamLeads: SupportCollection, supervisors: SupportCollection}
     */
    public function teamOptions(): array
    {
        return [
            'teamLeads' => User::query()
                ->where('is_active', true)
                ->whereDoesntHave('roles', fn ($role) => $role->where('name', 'SUPERADMIN'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->toBase(),
            'supervisors' => $this->activeHolders('QA'),
        ];
    }

    private function activeHolders(string $role): SupportCollection
    {
        return User::role($role)->where('is_active', true)->orderBy('name')->get(['id', 'name'])->toBase();
    }

    /**
     * "Buka Proyek" — Sprint 12 #19, by the PM who approved the RAB Proyek
     * since Sprint 23 K13 (ProjectOpeningPolicy; this method doesn't check
     * who acts). In one transaction the project (RAB Fix = the approved
     * quotation, contract value = its total, PM, optional Asisten PM, and
     * K14/K15: Kepala Tim — a user or a typed name —, optional Pengawas,
     * Tanggal Buka, Tanggal Mulai, optional Tanggal Selesai) and its
     * termins copied from the payment scheme the client approved. The
     * only way a project is created since Sprint 12 Sub 7.
     *
     * @param  array{name: string, pm_id: int|string, assistant_pm_id?: int|string|null, team_lead_id?: int|string|null, team_lead_name?: string|null, supervisor_ids?: list<int|string>|null, opened_on?: string|null, start_date: string, end_date?: string|null}  $data
     */
    public function openFromQuotation(ProjectOpening $opening, array $data, User $actor): Project
    {
        return DB::transaction(function () use ($opening, $data, $actor) {
            $opening = ProjectOpening::whereKey($opening->getKey())->lockForUpdate()->firstOrFail();

            if (! $opening->isWaiting()) {
                throw ValidationException::withMessages(['opening' => 'Proyek ini sudah dibuka.']);
            }

            $quotation = $opening->quotation()->with('paymentTerms')->firstOrFail();
            $lead = $opening->lead()->firstOrFail();

            $project = $this->createFromLead($lead->setRelation('quotation', $quotation), [
                'name' => $data['name'],
                'pm_id' => $data['pm_id'],
                'assistant_pm_id' => $data['assistant_pm_id'] ?? null,
                ...$this->teamLeadColumns($data),
                'supervisor_ids' => $data['supervisor_ids'] ?? [],
                'opened_on' => $data['opened_on'] ?? now()->toDateString(),
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'contract_value' => $quotation->total_amount,
                'quotation_id' => $quotation->id,
            ], $actor);

            // Sprint 29 Sub 08 — every RAB item becomes a work item (Satuan Upah set by the Pengawas).
            app(WorkItemService::class)->ensureForProject($project);

            // Sprint 24 D1/D2 — a new project starts at Gambar Kerja, in the design team's queue.
            $project->update(['phase' => ProjectPhase::GambarKerja->value, 'phase_changed_at' => now()]);
            app(WorkingDrawingService::class)->openForProject($project);

            $termins = $this->terminService->createFromPaymentTerms($project, $quotation);
            // Sprint 17 Sub 06 (K2) — a DP already billed from the RAB becomes the DP termin's invoice.
            $this->terminService->attachUpfrontInvoice($project, $quotation, $termins, $actor);
            // Sprint 17 Sub 03 (K2) — the DP is billable now: straight into Marketing's "Perlu Tindakan".
            $this->terminService->remindInvoices($project);

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
                'team_lead_id' => $project->team_lead_id,
                'team_lead_name' => $project->team_lead_name,
                'supervisor_ids' => $project->supervisors()->pluck('users.id')->all(),
                'opened_on' => $project->opened_on?->toDateString(),
                'start_date' => $data['start_date'],
                'end_date' => $project->end_date?->toDateString(),
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
    public function createFromLead(Lead $lead, array $data, ?User $actor = null): Project
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
            'team_lead_id' => $data['team_lead_id'] ?? null,
            'team_lead_name' => $data['team_lead_name'] ?? null,
            'opened_on' => $data['opened_on'] ?? now()->toDateString(),
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
            'contract_value' => $data['contract_value'],
        ]);
        // Sprint 29 Sub 06 — the Pengawas (one or more) live in project_supervisors; before the "Proyek Dibuka" notification below.
        $this->syncSupervisors($project, $data['supervisor_ids'] ?? [], $actor);

        // PRD §4.9 "Deal dikonfirmasi → PM, CEO, Finance, Logistics" — the
        // project's own PM (and Asisten PM) plus the divisions that act on
        // a new project (termins, material planning) and the lead's
        // Marketing, who invoices the termins (Sprint 12 #20). Sprint 23
        // K13: this is the CEO's info that the PM opened it; K15: the
        // Pengawas and a Kepala Tim with an account learn they're on it.
        $this->notificationService->notifyMany(
            User::role(['CEO', 'FINANCE', 'LOGISTICS'])->where('is_active', true)->get()
                ->push($project->pm, $project->assistantPm, $lead->assignee, $project->teamLead)
                ->concat($project->supervisors()->get())
                ->filter()
                ->unique('id'),
            NotificationType::ProjectOpened,
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
     * Sprint 23 K15 — also Tanggal Buka, Kepala Tim (user or typed name)
     * and Pengawas, each only when the form sends it.
     *
     * @param  array{name: string, opened_on?: string, start_date: string, end_date?: ?string, contract_value: numeric-string|int|float, status: string, note?: ?string, pm_id?: int|string, assistant_pm_id?: int|string|null, team_lead_id?: int|string|null, team_lead_name?: string|null, supervisor_ids?: list<int|string>|null}  $data
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
            // Sprint 12 D2 — the Asisten PM, when the form sends it (own audit entry).
            $assistantChanges = array_key_exists('assistant_pm_id', $data)
                && (int) ($data['assistant_pm_id'] ?? 0) !== (int) ($locked->assistant_pm_id ?? 0);
            $previousAssistant = $assistantChanges ? $locked->assistantPm()->first(['id', 'name']) : null;

            $locked->update([
                'name' => $data['name'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'contract_value' => $data['contract_value'],
                'status' => $newStatus->value,
                'pm_id' => $newPmId,
                ...($assistantChanges ? ['assistant_pm_id' => $data['assistant_pm_id'] ?: null] : []),
                // Sprint 23 K15 — Tanggal Buka, Kepala Tim, Pengawas (when the form sends them).
                ...(array_key_exists('opened_on', $data) ? ['opened_on' => $data['opened_on']] : []),
                ...(array_key_exists('team_lead_id', $data) || array_key_exists('team_lead_name', $data) ? $this->teamLeadColumns($data) : []),
            ]);

            // Sprint 29 Sub 06 — the Pengawas list when the form sends it.
            $supervisorChange = array_key_exists('supervisor_ids', $data)
                ? $this->syncSupervisors($locked, $data['supervisor_ids'] ?? [], $actor)
                : ['attached' => [], 'detached' => []];

            if ($assistantChanges) {
                $this->recordAssistantChange($locked, $previousAssistant, $actor);
            }

            $after = $this->snapshot($locked);
            $changed = array_keys(array_diff_assoc($after, $before));

            $supervisorsChanged = $supervisorChange['attached'] !== [] || $supervisorChange['detached'] !== [];

            if ($changed === [] && ! $supervisorsChanged) {
                return $locked;
            }

            $old = array_intersect_key($before, array_flip($changed));
            $new = array_intersect_key($after, array_flip($changed));

            if ($supervisorsChanged) {
                $old['supervisors_removed'] = $supervisorChange['detached'];
                $new['supervisors_added'] = $supervisorChange['attached'];
            }
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

            // Sprint 24 (left over from Sprint 23 Sub 07) — the new Pengawas learns
            // they run this project's execution flow; the previous one that they don't.
            if ($supervisorsChanged) {
                $this->notifySupervisorChange($locked, $supervisorChange['attached'], $supervisorChange['detached'], $actor);
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

        // Sprint 24 D12 — an open finishing finding holds the project too.
        if ($project->siteFindings()->open()->exists()) {
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
                    NotificationType::ProjectMaterialLeftover,
                    'Proyek Tertahan: Material',
                    "Semua timeline proyek \"{$project->name}\" lolos QA, tetapi proyek belum bisa COMPLETED karena "
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
            NotificationType::ProjectCompleted,
            'Proyek Selesai',
            "Semua timeline proyek \"{$project->name}\" lolos QA — proyek ditandai COMPLETED.",
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
                    ? 'Status COMPLETED hanya diset otomatis setelah semua timeline lolos QA.'
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
    /**
     * Sprint 12 decision #29 / D7 — the client approved a RAB Tambahan
     * (listener AddAddendumToProject): the contract value grows by its
     * total, its payment scheme becomes new termins after the existing
     * ones (invoice type TAMBAHAN, "di muka" due today) and its items
     * become allocatable (ProjectBudgetService::sourceItems()). Audited;
     * PM, Asisten PM, Marketing and Finance are told.
     */
    public function addAddendum(Quotation $addendum): Project
    {
        $project = Project::lockForUpdate()->findOrFail($addendum->project_id);
        $before = number_format((float) $project->contract_value, 2, '.', '');

        $project->update(['contract_value' => round((float) $project->contract_value + (float) $addendum->total_amount, 2)]);
        $termins = $this->terminService->createFromPaymentTerms($project, $addendum->loadMissing('paymentTerms'), now()->toDateString());
        $this->terminService->remindInvoices($project);
        // Sprint 29 Sub 08 — its items become work items too (wage editable even after Start Produksi).
        app(WorkItemService::class)->ensureForProject($project);

        $this->auditLogService->record('project.addendum_added', $project, ['contract_value' => $before], [
            'contract_value' => number_format((float) $project->contract_value, 2, '.', ''),
            'quotation_id' => $addendum->id,
            'addendum_total' => (float) $addendum->total_amount,
            'termins' => $termins->pluck('termin_number')->all(),
        ]);

        $this->notificationService->notifyMany(
            collect([$project->pm, $project->assistantPm, $project->lead?->assignee])
                ->merge(User::role('FINANCE')->where('is_active', true)->get()),
            NotificationType::ProjectAddendumAdded,
            'RAB Tambahan Disetujui Klien',
            "Klien menyetujui RAB Tambahan proyek \"{$project->name}\" (Rp ".number_format((float) $addendum->total_amount, 0, ',', '.').') — termin & item baru sudah ditambahkan.',
            ['project_id' => $project->id, 'quotation_id' => $addendum->id],
        );

        return $project;
    }

    private function recalculateTermins(Project $project, mixed $contractValue): int
    {
        // Sprint 12 #29: only the RAB Fix's termins follow the contract
        // value; an addendum's are fixed amounts of their own RAB.
        $addendaTotal = (float) $project->addenda()->where('status', QuotationStatus::ClientApproved->value)->sum('total_amount');
        $contractValue = (float) $contractValue - $addendaTotal;
        $termins = Termin::query()
            ->where('project_id', $project->id)
            ->where(fn ($query) => $query->whereNull('quotation_id')->orWhere('quotation_id', $project->quotation_id))
            ->lockForUpdate()
            ->get();

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

    /** Sprint 12 D2 — audit `project.assistant_pm_changed`, tell the new and the previous Asisten PM. */
    private function recordAssistantChange(Project $project, ?User $previous, User $actor): void
    {
        $new = $project->assistantPm()->first(['id', 'name']);

        $this->auditLogService->record('project.assistant_pm_changed', $project, [
            'assistant_pm_id' => $previous?->id,
            'assistant_pm' => $previous?->name,
        ], [
            'assistant_pm_id' => $new?->id,
            'assistant_pm' => $new?->name,
        ], $actor);

        if ($new) {
            $this->notificationService->notify(
                $new,
                NotificationType::ProjectAssistantAssigned,
                'Ditunjuk sebagai Asisten PM',
                "Anda ditunjuk sebagai Asisten PM proyek \"{$project->name}\" oleh {$actor->name}.",
                ['project_id' => $project->id],
            );
        }

        if ($previous) {
            $this->notificationService->notify(
                $previous,
                NotificationType::ProjectAssistantUnassigned,
                'Pergantian Asisten PM',
                "Anda bukan lagi Asisten PM proyek \"{$project->name}\".",
                ['project_id' => $project->id],
            );
        }
    }

    /**
     * Sprint 29 Sub 06 (A3) — put the project's Pengawas list to `$ids`, in
     * the given order (the first stays the one a single name shows).
     *
     * @param  array<int, int|string>  $ids
     * @return array{attached: list<int>, detached: list<int>}
     */
    public function syncSupervisors(Project $project, array $ids, ?User $actor): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'filled'))));
        $current = $project->supervisors()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $attached = array_values(array_diff($ids, $current));
        $detached = array_values(array_diff($current, $ids));

        if ($detached !== []) {
            $project->supervisors()->detach($detached);
        }

        foreach ($attached as $id) {
            $project->supervisors()->attach($id, ['assigned_by' => $actor?->id]);
        }

        $project->unsetRelation('supervisors');

        return ['attached' => $attached, 'detached' => $detached];
    }

    /**
     * @param  list<int>  $attached
     * @param  list<int>  $detached
     */
    private function notifySupervisorChange(Project $project, array $attached, array $detached, User $actor): void
    {
        $this->notificationService->notifyMany(
            User::whereIn('id', $attached)->get(),
            NotificationType::ProjectSupervisorAssigned,
            'Ditunjuk sebagai Pengawas (QA/QC)',
            "Anda ditunjuk sebagai Pengawas proyek \"{$project->name}\" oleh {$actor->name} — cek tab Eksekusi.",
            ['project_id' => $project->id],
        );

        $this->notificationService->notifyMany(
            User::whereIn('id', $detached)->get(),
            NotificationType::ProjectSupervisorUnassigned,
            'Pergantian Pengawas',
            "Anda bukan lagi Pengawas proyek \"{$project->name}\".",
            ['project_id' => $project->id],
        );
    }

    /** PRD §4.9-style heads-up to both sides of a PM hand-over. */
    private function notifyPmChange(Project $project, User $newPm, ?User $previousPm, User $actor): void
    {
        $this->notificationService->notify(
            $newPm,
            NotificationType::ProjectPmAssigned,
            'Ditunjuk sebagai Project Manager',
            "Anda ditunjuk sebagai Project Manager proyek \"{$project->name}\" oleh {$actor->name}.",
            ['project_id' => $project->id],
        );

        if ($previousPm) {
            $this->notificationService->notify(
                $previousPm,
                NotificationType::ProjectPmUnassigned,
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
            'opened_on' => $project->opened_on?->toDateString(),
            'start_date' => $project->start_date?->toDateString(),
            'end_date' => $project->end_date?->toDateString(),
            'contract_value' => number_format((float) $project->contract_value, 2, '.', ''),
            'team_lead_id' => $project->team_lead_id === null ? null : (int) $project->team_lead_id,
            'team_lead_name' => $project->team_lead_name,
        ];
    }

    /**
     * Sprint 23 K14/K15 — the Kepala Tim is a user **or** a typed name
     * (outsider/vendor), never both: a picked user clears the name.
     *
     * @return array{team_lead_id: int|null, team_lead_name: string|null}
     */
    private function teamLeadColumns(array $data): array
    {
        $userId = filled($data['team_lead_id'] ?? null) ? (int) $data['team_lead_id'] : null;
        $name = $userId === null && filled($data['team_lead_name'] ?? null) ? trim($data['team_lead_name']) : null;

        return ['team_lead_id' => $userId, 'team_lead_name' => $name];
    }

    /** Money math in integer cents — same approach as TerminService. */
    private function toCents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }
}
