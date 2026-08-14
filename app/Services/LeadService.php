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

        if ($newStatus === LeadStatus::Lost->value && empty($data['lost_reason'])) {
            throw ValidationException::withMessages([
                'lost_reason' => 'Alasan lost wajib diisi.',
            ]);
        }

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
}
