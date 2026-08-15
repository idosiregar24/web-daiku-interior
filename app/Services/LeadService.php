<?php

namespace App\Services;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\PipelineLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeadService
{
    public function __construct(private ProjectService $projectService) {}

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
     * PRD §4.3 actually gates this behind the Quotation being APPROVED
     * ("Konversi ke Project hanya bisa dilakukan setelah status APPROVED
     * dan konfirmasi Deal dari Marketing") — Quotation approval doesn't
     * exist yet (Sprint 3), so for now this only checks the Lead's own
     * status. Add the Quotation check here once that module ships.
     */
    public function confirmDeal(Lead $lead, array $projectData, User $actor): Lead
    {
        if ($lead->status !== LeadStatus::DealDesain) {
            throw ValidationException::withMessages([
                'status' => 'Deal hanya bisa dikonfirmasi dari lead berstatus DEAL_DESAIN.',
            ]);
        }

        return DB::transaction(function () use ($lead, $projectData, $actor) {
            $lead = $this->applyStatusChange(
                $lead,
                LeadStatus::Closing->value,
                ['note' => 'Deal dikonfirmasi, proyek dibuat.'],
                $actor,
            );

            $this->projectService->createFromLead($lead, $projectData);

            return $lead;
        });
    }
}
