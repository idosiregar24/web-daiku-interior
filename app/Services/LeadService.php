<?php

namespace App\Services;

use App\Enums\LeadStatus;
use App\Enums\QuotationStatus;
use App\Models\Lead;
use App\Models\PipelineLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeadService
{
    public function __construct(
        private ProjectService $projectService,
        private NotificationService $notificationService,
        private AuditLogService $auditLogService,
    ) {}

    /**
     * PRD §4.9 "Lead follow-up jatuh tempo → Marketing yang bertugas" —
     * run each morning (LeadFollowUpReminderJob). Covers leads due today
     * and still-overdue ones: an unanswered follow-up keeps reminding
     * daily until Marketing moves the date or the lead. Idempotent per
     * day via alreadySentToday().
     */
    public function sendFollowUpReminders(): int
    {
        $sent = 0;

        Lead::query()
            ->with('assignee')
            ->whereNotNull('follow_up_date')
            ->whereNotIn('status', [LeadStatus::Lost->value, LeadStatus::Closing->value])
            ->whereDate('follow_up_date', '<=', now('Asia/Jakarta')->toDateString())
            ->each(function (Lead $lead) use (&$sent) {
                $marketing = $lead->assignee;

                if (! $marketing || $this->notificationService->alreadySentToday($marketing, 'lead_follow_up_due', 'lead_id', $lead->id)) {
                    return;
                }

                $isOverdue = $lead->follow_up_date->isBefore(now('Asia/Jakarta')->startOfDay());

                $this->notificationService->notify(
                    $marketing,
                    'lead_follow_up_due',
                    $isOverdue ? 'Follow-up Terlewat' : 'Follow-up Hari Ini',
                    "Lead \"{$lead->client_name}\" ({$lead->contact}) dijadwalkan follow-up "
                        .($isOverdue ? 'sejak '.$lead->follow_up_date->translatedFormat('d F Y').'.' : 'hari ini.'),
                    ['lead_id' => $lead->id],
                );

                $sent++;
            });

        return $sent;
    }

    /**
     * Create a lead and its initial pipeline log entry.
     */
    public function create(array $data, User $actor): Lead
    {
        return DB::transaction(function () use ($data, $actor) {
            $lead = Lead::create([
                ...$data,
                'status' => $data['status'] ?? LeadStatus::FollowUp->value,
                'created_by' => $actor->id,
            ]);

            PipelineLog::create([
                'lead_id' => $lead->id,
                'from_status' => null,
                'to_status' => $lead->status->value,
                'changed_by' => $actor->id,
                'note' => 'Lead dibuat.',
            ]);

            return $lead;
        });
    }

    /**
     * Plain field edits (name, contact, notes, etc.) — never touches
     * `status`, that only ever changes through `changeStatus()` so a
     * PipelineLog entry is never skipped.
     */
    public function update(Lead $lead, array $data): Lead
    {
        unset($data['status']);
        $lead->update($data);

        return $lead;
    }

    /**
     * Change a lead's pipeline status, per PRD §4.1 business rules:
     * - Alasan lost wajib diisi saat status pindah ke LOST.
     * - Lead LOST bersifat terminal — tidak bisa diubah kembali (buat lead
     *   baru jika klien kembali).
     * Every change writes a PipelineLog entry — this is the only place
     * lead status should ever be mutated from.
     */
    public function changeStatus(Lead $lead, array $data, User $actor): Lead
    {
        if ($lead->status === LeadStatus::Lost) {
            throw ValidationException::withMessages([
                'status' => 'Lead yang sudah LOST tidak bisa diubah statusnya. Buat lead baru jika klien kembali.',
            ]);
        }

        $newStatus = $data['status'];

        // CLOSING only happens through confirmDeal() — it's the step that
        // also creates the Project (PRD §4.4), so skipping straight to it
        // here would leave a CLOSING lead with no Project behind it.
        // confirmDeal() itself reaches CLOSING via applyStatusChange()
        // directly, not through this guarded entry point.
        if ($newStatus === LeadStatus::Closing->value) {
            throw ValidationException::withMessages([
                'status' => 'Status CLOSING hanya bisa didapat lewat konfirmasi Deal (lihat aksi "Konfirmasi Deal").',
            ]);
        }

        if ($newStatus === LeadStatus::Lost->value && empty($data['lost_reason'])) {
            throw ValidationException::withMessages([
                'lost_reason' => 'Alasan lost wajib diisi.',
            ]);
        }

        return $this->applyStatusChange($lead, $newStatus, $data, $actor);
    }

    /**
     * Raw status mutation + PipelineLog write, with none of changeStatus()'s
     * guards — only called from within this class (changeStatus() after its
     * checks pass, and confirmDeal() for the CLOSING transition that
     * changeStatus() otherwise refuses).
     */
    private function applyStatusChange(Lead $lead, string $newStatus, array $data, User $actor): Lead
    {
        return DB::transaction(function () use ($lead, $newStatus, $data, $actor) {
            $fromStatus = $lead->status->value;

            $lead->update([
                'status' => $newStatus,
                'lost_reason' => $newStatus === LeadStatus::Lost->value
                    ? $data['lost_reason']
                    : $lead->lost_reason,
            ]);

            PipelineLog::create([
                'lead_id' => $lead->id,
                'from_status' => $fromStatus,
                'to_status' => $newStatus,
                'changed_by' => $actor->id,
                'note' => $data['note'] ?? null,
            ]);

            return $lead->fresh();
        });
    }

    /**
     * PRD §4.4 "Project hanya bisa dibuat dari Lead yang berstatus DEAL" +
     * §4.1's DEAL_DESAIN→CLOSING pipeline: confirming a deal closes the
     * lead's pipeline (CLOSING) and creates the execution Project in one
     * transaction. `$projectData` needs `name`, `pm_id`, `start_date`,
     * `contract_value` — see ConfirmLeadDealRequest.
     *
     * PRD §4.3 "Konversi ke Project hanya bisa dilakukan setelah status
     * APPROVED dan konfirmasi Deal dari Marketing": the quotation must
     * have cleared both internal gates (CEO→PM, which leaves it
     * SENT_TO_CLIENT). Marketing confirming the deal *is* the client's
     * acceptance of that offer — the SENT_TO_CLIENT→APPROVED transition
     * QuotationStatus reserved but no action produced until now — so it
     * is recorded here, in the same transaction as the Project.
     */
    public function confirmDeal(Lead $lead, array $projectData, User $actor): Lead
    {
        if ($lead->status !== LeadStatus::DealDesain) {
            throw ValidationException::withMessages([
                'status' => 'Deal hanya bisa dikonfirmasi dari lead berstatus DEAL_DESAIN.',
            ]);
        }

        $quotation = $lead->quotation;

        if (! $quotation || ! in_array($quotation->status, [QuotationStatus::SentToClient, QuotationStatus::Approved], true)) {
            throw ValidationException::withMessages([
                'status' => 'Deal hanya bisa dikonfirmasi setelah quotation disetujui CEO & PM (status SENT_TO_CLIENT).',
            ]);
        }

        return DB::transaction(function () use ($lead, $quotation, $projectData, $actor) {
            $oldQuotationStatus = $quotation->status;
            $quotation->update(['status' => QuotationStatus::Approved->value]);

            // PRD §9.4 — the client's acceptance is the final quotation approval.
            $this->auditLogService->record(
                'quotation.client_approved',
                $quotation,
                ['status' => $oldQuotationStatus],
                ['status' => $quotation->status, 'total_amount' => $quotation->total_amount],
                $actor,
            );

            $lead = $this->applyStatusChange(
                $lead,
                LeadStatus::Closing->value,
                ['note' => 'Deal dikonfirmasi, proyek dibuat.'],
                $actor,
            );

            $project = $this->projectService->createFromLead($lead->setRelation('quotation', $quotation), $projectData);

            // PRD §4.9 "Deal dikonfirmasi → PM, CEO, Finance, Logistics" —
            // the project's own PM plus the divisions that act on a new
            // project (termin scheduling, material planning).
            $this->notificationService->notifyMany(
                User::role(['CEO', 'FINANCE', 'LOGISTICS'])->where('is_active', true)->get()->push($project->pm),
                'deal_confirmed',
                'Deal Dikonfirmasi',
                "Deal \"{$lead->client_name}\" dikonfirmasi — proyek \"{$project->name}\" dibuat dengan PM {$project->pm->name}.",
                ['project_id' => $project->id, 'lead_id' => $lead->id],
            );

            return $lead;
        });
    }
}
