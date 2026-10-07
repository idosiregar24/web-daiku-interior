<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\LeadStatus;
use App\Enums\LeadSurveyStatus;
use App\Enums\NotificationType;
use App\Enums\QuotationType;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\LeadCategory;
use App\Models\LeadFollowUp;
use App\Models\LeadSource;
use App\Models\LeadSurvey;
use App\Models\PipelineLog;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeadService
{
    /** Sprint 12 #7 — "Minta RAB …" options of the Ajukan dialog → the quotation type asked for. */
    public const RAB_REQUEST_TYPES = [
        'RAB_SURVEY' => QuotationType::Survey,
        'RAB_DESAIN' => QuotationType::Desain,
        'RAB_PROYEK' => QuotationType::Proyek,
        // Sprint 14 Sub 02 — "Buat RAB → Lainnya": a RAB Proyek with its own name.
        'RAB_LAINNYA' => QuotationType::Proyek,
    ];

    public function __construct(
        private NotificationService $notificationService,
        private AuditLogService $auditLogService,
        private DesignService $designService,
        private QuotationService $quotationService,
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
        $today = now('Asia/Jakarta')->toDateString();

        // Sprint 12: the open follow-up (FU-n) due today or earlier, per lead.
        Lead::query()
            ->with(['assignee', 'followUps' => fn ($q) => $q->pending()->where('scheduled_date', '<=', $today)])
            ->whereNotIn('status', [LeadStatus::Lost->value, LeadStatus::Closing->value])
            ->whereHas('followUps', fn ($q) => $q->pending()->where('scheduled_date', '<=', $today))
            ->each(function (Lead $lead) use (&$sent) {
                $marketing = $lead->assignee;

                if (! $marketing || $this->notificationService->alreadySentToday($marketing, NotificationType::LeadFollowUpDue, 'lead_id', $lead->id)) {
                    return;
                }

                /** @var LeadFollowUp $followUp */
                $followUp = $lead->followUps->sortBy('scheduled_date')->first();
                $isOverdue = $followUp->scheduled_date->isBefore(now('Asia/Jakarta')->startOfDay());

                $this->notificationService->notify(
                    $marketing,
                    NotificationType::LeadFollowUpDue,
                    $isOverdue ? 'Follow-up Terlewat' : 'Follow-up Hari Ini',
                    "Lead \"{$lead->client_name}\" ({$lead->contactLabel()}) dijadwalkan FU-{$followUp->sequence} "
                        .($isOverdue ? 'sejak '.$followUp->scheduled_date->translatedFormat('d F Y').'.' : 'hari ini.'),
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
            $followUpDate = $data['follow_up_date'] ?? null;
            unset($data['follow_up_date']);

            $lead = Lead::create([
                ...$this->syncMasterReferences($data),
                'status' => $data['status'] ?? LeadStatus::FollowUp->value,
                'created_by' => $actor->id,
            ]);

            // Sprint 12: the first follow-up date given on the form is FU-1.
            if (filled($followUpDate)) {
                $lead->followUps()->create(['sequence' => 1, 'scheduled_date' => $followUpDate, 'created_by' => $actor->id]);
            }

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
        $lead->update($this->syncMasterReferences($data));

        return $lead;
    }

    /**
     * Resolve `lead_source_id` / `lead_category_id` (Data Master FKs) and
     * keep the legacy `source` / `category` string columns in sync with the
     * master row's `name` — every existing reader (CRM dashboard bySource,
     * analytics, exports) still reads the strings.
     *
     * The FK wins when given (the CRM form always sends it). Legacy callers
     * that still pass only a `source`/`category` string (e.g.
     * DemoDataSeeder) are tolerated: the string is matched to a master row
     * case-insensitively, creating one if missing — same rule as the
     * backfill migration.
     */
    private function syncMasterReferences(array $data): array
    {
        if (! empty($data['lead_source_id'])) {
            $data['source'] = LeadSource::findOrFail($data['lead_source_id'])->name;
        } elseif (filled($data['source'] ?? null)) {
            $source = LeadSource::findOrCreateByName($data['source']);
            $data['lead_source_id'] = $source->id;
            $data['source'] = $source->name;
        }

        if (array_key_exists('lead_category_id', $data)) {
            $data['category'] = $data['lead_category_id']
                ? LeadCategory::findOrFail($data['lead_category_id'])->name
                : null;
        } elseif (array_key_exists('category', $data)) {
            if (filled($data['category'])) {
                $category = LeadCategory::findOrCreateByName($data['category']);
                $data['lead_category_id'] = $category->id;
                $data['category'] = $category->name;
            } else {
                $data['category'] = null;
                $data['lead_category_id'] = null;
            }
        }

        return $data;
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
     * Sprint 12 Sub 5 (replaces Marketing's "Konfirmasi Deal"): the client
     * approved the RAB Proyek on its public link — that is the deal. The
     * lead closes (CLOSING; the pipeline log is written in the name of the
     * Marketing user who sent the link) and its design goes into
     * production (Sprint 9 decision #4). The project itself is opened by
     * the CEO (QueueProjectOpening → "Buka Proyek" pop-up, Sub 7).
     * A lead that is already closed or lost is left alone.
     */
    public function closeOnProjectRabApproval(Lead $lead, Quotation $quotation, User $sender): Lead
    {
        if (! in_array($lead->status, [LeadStatus::FollowUp, LeadStatus::DealDesain], true)) {
            return $lead;
        }

        $lead = $this->applyStatusChange($lead, LeadStatus::Closing->value, [
            'note' => "RAB Proyek versi {$quotation->version} disetujui klien lewat link penawaran.",
        ], $sender);

        $this->designService->syncWithPipeline($lead->id, DesignService::EVENT_DEAL_CONFIRMED);

        $this->notificationService->notifyMany(
            User::role('PM')->where('is_active', true)->get(),
            NotificationType::DealConfirmed,
            'Deal — RAB Proyek Disetujui Klien',
            "Klien \"{$lead->client_name}\" menyetujui RAB Proyek (".'Rp '.number_format((float) $quotation->total_amount, 0, ',', '.').') — proyeknya menunggu dibuka CEO.',
            ['lead_id' => $lead->id, 'quotation_id' => $quotation->id],
        );

        return $lead;
    }

    // ── Sprint 12 Sub 2: follow-up bertingkat & survey ───────────────────

    /**
     * Decision #2 — the next numbered follow-up (FU-n). Numbering happens
     * under a lock on the lead so two people adding at once can't both
     * take the same number. From FU-5 on the UI suggests marking the lead
     * Lost (LeadFollowUp::SUGGEST_LOST_FROM) — a hint, never a block.
     */
    public function addFollowUp(Lead $lead, array $data, User $actor): LeadFollowUp
    {
        return DB::transaction(function () use ($lead, $data, $actor) {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->id);
            $this->ensureOpen($lead, 'scheduled_date');

            return $lead->followUps()->create([
                'sequence' => (int) $lead->followUps()->max('sequence') + 1,
                'scheduled_date' => $data['scheduled_date'],
                'result_note' => null,
                'created_by' => $actor->id,
            ]);
        });
    }

    /** A follow-up is done once Marketing records what came out of it. */
    public function completeFollowUp(LeadFollowUp $followUp, array $data): LeadFollowUp
    {
        if ($followUp->done_at !== null) {
            throw ValidationException::withMessages(['result_note' => "FU-{$followUp->sequence} sudah ditandai selesai."]);
        }

        $followUp->forceFill(['done_at' => now(), 'result_note' => trim($data['result_note'])])->save();

        return $followUp;
    }

    /**
     * Decision #3 — schedule a site survey (repeatable). The address and
     * Maps link default to the lead's. Inside Pekanbaru it's free and
     * DIJADWALKAN; outside it waits for the paid RAB Jasa Survey
     * (MENUNGGU_BAYAR) until Finance verifies the payment (Sub 6 calls
     * markSurveyReady()).
     */
    public function scheduleSurvey(Lead $lead, array $data, User $actor): LeadSurvey
    {
        return DB::transaction(function () use ($lead, $data, $actor) {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->id);
            $this->ensureOpen($lead, 'scheduled_at');
            $outside = (bool) ($data['is_outside_pekanbaru'] ?? false);

            $survey = $lead->surveys()->create([
                'sequence' => (int) $lead->surveys()->max('sequence') + 1,
                'scheduled_at' => $data['scheduled_at'],
                'address' => filled($data['address'] ?? null) ? $data['address'] : $lead->address,
                'maps_url' => filled($data['maps_url'] ?? null) ? $data['maps_url'] : $lead->maps_url,
                'is_outside_pekanbaru' => $outside,
                'status' => ($outside ? LeadSurveyStatus::MenungguBayar : LeadSurveyStatus::Dijadwalkan)->value,
                'created_by' => $actor->id,
            ]);

            // Sprint 17 Sub 02 — the RAB Jasa Survey may have been asked for
            // (or even paid) before this survey was scheduled.
            if ($outside && $this->quotationService->linkSurveyToRab($lead)?->is($survey)) {
                $this->settleSurveyPayment($survey->refresh());
            }

            return $survey;
        });
    }

    /**
     * Sprint 17 Sub 02 — a survey waiting for payment whose RAB Jasa Survey
     * already has a verified invoice goes SIAP now: InvoiceVerified fired
     * before the two were linked, so MarkSurveyReadyOnInvoiceVerified never
     * saw it. Used after linking and by `daiku:relink-surveys`.
     */
    public function settleSurveyPayment(LeadSurvey $survey): LeadSurvey
    {
        return $this->isSurveyPaid($survey) ? $this->markSurveyReady($survey) : $survey;
    }

    /** Waiting for payment, linked to a RAB Jasa Survey, and that RAB's invoice is verified. */
    public function isSurveyPaid(LeadSurvey $survey): bool
    {
        return $survey->status === LeadSurveyStatus::MenungguBayar
            && $survey->quotation_id !== null
            && Invoice::where('quotation_id', $survey->quotation_id)
                ->where('status', InvoiceStatus::Terverifikasi->value)
                ->exists();
    }

    /** Reschedule / correct the place of a survey that's still open. Inside/outside Pekanbaru is fixed. */
    public function updateSurvey(LeadSurvey $survey, array $data): LeadSurvey
    {
        $this->ensureSurveyOpen($survey);

        $survey->update([
            'scheduled_at' => $data['scheduled_at'],
            'address' => $data['address'] ?? $survey->address,
            'maps_url' => $data['maps_url'] ?? $survey->maps_url,
        ]);

        return $survey;
    }

    /** Cancelling needs a reason and is audited. */
    public function cancelSurvey(LeadSurvey $survey, string $reason, User $actor): LeadSurvey
    {
        $this->ensureSurveyOpen($survey);
        $before = $survey->status->value;

        return DB::transaction(function () use ($survey, $reason, $actor, $before) {
            $survey->update(['status' => LeadSurveyStatus::Batal->value, 'cancel_reason' => trim($reason)]);

            $this->auditLogService->record('crm.survey_cancelled', $survey, ['status' => $before], [
                'status' => LeadSurveyStatus::Batal->value,
                'lead_id' => $survey->lead_id,
                'sequence' => $survey->sequence,
                'reason' => $survey->cancel_reason,
            ], $actor);

            return $survey;
        });
    }

    /**
     * Marketing records the survey as done. An outside-Pekanbaru survey
     * can't be until it's paid and verified (SIAP).
     */
    public function completeSurvey(LeadSurvey $survey, array $data): LeadSurvey
    {
        if ($survey->status === LeadSurveyStatus::MenungguBayar) {
            throw ValidationException::withMessages([
                'result_note' => 'Survey luar Pekanbaru belum bisa diselesaikan — pembayaran RAB Jasa Survey belum diverifikasi Finance.',
            ]);
        }

        if (! in_array($survey->status, [LeadSurveyStatus::Dijadwalkan, LeadSurveyStatus::Siap], true)) {
            throw ValidationException::withMessages(['result_note' => 'Survey ini sudah '.strtolower($survey->status->label()).'.']);
        }

        $survey->update(['status' => LeadSurveyStatus::Selesai->value, 'result_note' => trim($data['result_note'])]);

        return $survey;
    }

    /**
     * Payment of the RAB Jasa Survey verified by Finance → the survey may
     * go ahead. Called by Sub 6's listener only — there is no route; a
     * manual "siap" by Marketing doesn't exist (decision #3).
     */
    public function markSurveyReady(LeadSurvey $survey): LeadSurvey
    {
        if ($survey->status !== LeadSurveyStatus::MenungguBayar) {
            return $survey;
        }

        $survey->update(['status' => LeadSurveyStatus::Siap->value]);

        $this->notificationService->notifyMany(
            [$survey->lead->assignee],
            NotificationType::LeadSurveyReady,
            'Survey Siap Berangkat',
            "Pembayaran survey \"{$survey->lead->client_name}\" sudah diverifikasi — survey #{$survey->sequence} siap berangkat.",
            ['lead_id' => $survey->lead_id],
        );

        return $survey;
    }

    /**
     * "Ajukan Desain/Survey" (decision #5 — replaces "Deal Desain"): the
     * lead moves to DEAL_DESAIN (shown as "Pengajuan Desain/Survey") and,
     * for a survey, the survey is scheduled in the same transaction.
     * RAB_SURVEY / RAB_DESAIN / RAB_PROYEK ask the Estimator for that RAB
     * (Sprint 12 #7 — QuotationService::request(), note required).
     *
     * @param  array{type: string, note?: ?string, scheduled_at?: string, address?: ?string, maps_url?: ?string, is_outside_pekanbaru?: bool}  $data
     */
    public function submitRequest(Lead $lead, array $data, User $actor): Lead
    {
        return DB::transaction(function () use ($lead, $data, $actor) {
            if ($data['type'] === 'SURVEY') {
                $this->scheduleSurvey($lead, $data, $actor);
            }

            $rabType = self::RAB_REQUEST_TYPES[$data['type']] ?? null;

            $quotation = null;

            if ($rabType !== null) {
                $quotation = $this->quotationService->request(
                    $lead,
                    $rabType,
                    (string) ($data['note'] ?? ''),
                    $actor,
                    $data['reference_links'] ?? [],
                    $data['reference_photos'] ?? [],
                    $data['type'] === 'RAB_LAINNYA' ? ($data['custom_name'] ?? null) : null,
                );
            }

            if ($lead->status === LeadStatus::FollowUp) {
                $lead = $this->changeStatus($lead, [
                    'status' => LeadStatus::DealDesain->value,
                    'note' => trim(match (true) {
                        $data['type'] === 'SURVEY' => 'Pengajuan survey.',
                        default => "Permintaan {$quotation->title()}.",
                    }.' '.($data['note'] ?? '')),
                ], $actor);
            }

            return $lead;
        });
    }

    private function ensureOpen(Lead $lead, string $field): void
    {
        if (in_array($lead->status, [LeadStatus::Lost, LeadStatus::Closing], true)) {
            throw ValidationException::withMessages([
                $field => 'Lead ini sudah '.$lead->status->value.' — tidak bisa menambah follow-up atau survey.',
            ]);
        }
    }

    private function ensureSurveyOpen(LeadSurvey $survey): void
    {
        if (! $survey->status->isOpen()) {
            throw ValidationException::withMessages(['scheduled_at' => 'Survey ini sudah '.strtolower($survey->status->label()).'.']);
        }
    }
}
