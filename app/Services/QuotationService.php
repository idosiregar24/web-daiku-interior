<?php

namespace App\Services;

use App\Enums\LeadStatus;
use App\Enums\LeadSurveyStatus;
use App\Enums\PaymentTermTrigger;
use App\Enums\QuotationStatus;
use App\Enums\QuotationType;
use App\Events\QuotationClientApproved;
use App\Models\Design;
use App\Models\Lead;
use App\Models\LeadSurvey;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\QuotationApproval;
use App\Models\QuotationItem;
use App\Models\QuotationItemReview;
use App\Models\QuotationPaymentTerm;
use App\Models\QuotationReference;
use App\Models\QuotationRevision;
use App\Models\QuotationShareLink;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * RAB builder (Sprint 2 Week 4; Sprint 12 #11–#12 sections, discount,
 * rounding, payment scheme) and its approval flow — Sprint 12 decisions
 * #7–#10, which replace PRD §4.3/§6.2/§7.1's CEO → PM order (state
 * machine in QuotationStatus's docblock): Marketing asks (request()), the
 * Estimator drafts (startDraft(), saveRab()) and submits; PM / Asisten PM
 * mark every item ✔/✘ and approve or return it (review()); a RAB Proyek
 * then needs the CEO's review too, so the CEO can never decide before the
 * PM. The Estimator hands the final RAB to Marketing (sendToMarketing()),
 * Marketing sends it to the client (sendToClient(), starts the
 * VALIDITY_DAYS validity period and creates the public link). The client
 * approves on that link (clientApprove() → CLIENT_APPROVED,
 * QuotationClientApproved); a rejection is recorded by Marketing
 * (clientReject()). Every return/rejection closes the version into
 * `quotation_revisions` and reopens the next version as DRAFT
 * (closeVersion()). The lead's design follows the project RAB via
 * DesignService::syncWithPipeline().
 */
class QuotationService
{
    /** PRD §4.3 "Validity Period: Tanggal berlaku penawaran (default 14 hari dari tanggal kirim)". */
    public const VALIDITY_DAYS = 14;

    /** Sprint 12 decision #12 / D1 — at most 6 payment rows, the DP included. */
    public const MAX_PAYMENT_TERMS = 6;

    /** Sprint 12 #7 — who reviews at each stage (SUPERADMIN acts as either). */
    public const REVIEW_ROLES = [
        'PM' => ['PM', 'ASISTEN_PM'],
        'CEO' => ['CEO'],
    ];

    public function __construct(
        private NotificationService $notificationService,
        private AuditLogService $auditLogService,
        private LetterNumberService $letterNumbers,
    ) {}

    /**
     * PRD §4.3 "Quotation hanya bisa dibuat jika Design sudah clientAcc =
     * true" — called from DesignService::clientAcc(), never directly from
     * a controller (there's no standalone "create quotation" entry point;
     * it's always a side effect of the Client ACC trigger).
     */
    public function createFromDesign(Design $design, User $actor): Quotation
    {
        if (! $design->client_acc) {
            throw ValidationException::withMessages([
                'design_id' => 'Quotation hanya bisa dibuat dari desain yang sudah di-ACC klien.',
            ]);
        }

        // Sprint 12: Marketing may already have asked for the project RAB
        // (it doesn't need an accepted design, decision #6) — that one is it.
        if ($existing = $design->lead->quotation()->first()) {
            return $existing;
        }

        $quotation = Quotation::create([
            'lead_id' => $design->lead_id,
            'type' => QuotationType::Proyek->value,
            'status' => QuotationStatus::Draft->value,
            'created_by' => $actor->id,
        ]);
        $this->ensureDefaultPaymentTerms($quotation);

        return $quotation;
    }

    /**
     * Sprint 12 decision #7 — Marketing asks the Estimator for a RAB Jasa
     * Survey, Jasa Desain or Proyek (a note is required). One running
     * quotation per type per lead; a RAB Jasa Survey pays for the lead's
     * outside-Pekanbaru survey waiting for payment, if there is one.
     * Sprint 14 Sub 01: reference links and photos may ride along.
     * Sprint 14 Sub 02: a RAB Proyek may carry its own name ("Buat RAB →
     * Lainnya", e.g. "Renovasi Pagar") — same flow, only the title differs.
     *
     * @param  list<string>  $links
     * @param  list<UploadedFile>  $photos
     */
    public function request(Lead $lead, QuotationType $type, string $note, User $actor, array $links = [], array $photos = [], ?string $customName = null): Quotation
    {
        $customName = $type === QuotationType::Proyek ? (trim((string) $customName) ?: null) : null;

        if (in_array($lead->status, [LeadStatus::Lost, LeadStatus::Closing], true)) {
            throw ValidationException::withMessages(['type' => "Lead ini sudah {$lead->status->value} — tidak bisa meminta RAB baru."]);
        }

        $running = $lead->quotations()
            ->where('type', $type->value)
            ->whereNotIn('status', QuotationStatus::closedValues())
            ->first();

        if ($running) {
            throw ValidationException::withMessages(['type' => "Masih ada {$running->title()} yang berjalan untuk lead ini — selesaikan atau batalkan dulu."]);
        }

        return DB::transaction(function () use ($lead, $type, $note, $actor, $links, $photos, $customName) {
            $survey = $type === QuotationType::Survey
                ? $lead->surveys()->where('status', LeadSurveyStatus::MenungguBayar->value)->whereNull('quotation_id')->latest('id')->first()
                : null;

            $quotation = Quotation::create([
                'lead_id' => $lead->id,
                'type' => $type->value,
                'custom_name' => $customName,
                'lead_survey_id' => $survey?->id,
                'status' => QuotationStatus::Diminta->value,
                'created_by' => $actor->id,
                'requested_by' => $actor->id,
                'request_note' => trim($note),
            ]);

            $survey?->update(['quotation_id' => $quotation->id]);
            $attached = $this->attachReferences($quotation, $links, $photos, $actor);

            $this->notificationService->notifyRoles(
                ['ESTIMATOR'],
                'quotation_requested',
                "Permintaan {$quotation->title()}",
                "{$actor->name} meminta {$quotation->title()} untuk \"{$lead->client_name}\": ".trim($note).$attached,
                ['quotation_id' => $quotation->id, 'lead_id' => $lead->id],
            );

            return $quotation;
        });
    }

    /**
     * Sprint 12 decision #29 — "Minta RAB Tambahan" on a running project
     * (Marketing / its PM, note required): a PROYEK quotation on top of the
     * RAB Fix (`parent_quotation_id`, `project_id`), DIMINTA, through the
     * same flow — Estimator → PM / Asisten PM → CEO → client link. Its
     * approval adds to the project (ProjectService::addAddendum()), never
     * opens a new one. One running addendum per project at a time.
     *
     * @param  list<string>  $links
     * @param  list<UploadedFile>  $photos
     */
    public function requestAddendum(Project $project, string $note, User $actor, array $links = [], array $photos = []): Quotation
    {
        if ($project->quotation_id === null) {
            throw ValidationException::withMessages(['note' => 'Proyek ini belum tertaut ke RAB Fix — RAB Tambahan tidak bisa diminta.']);
        }

        if ($project->isClosed()) {
            throw ValidationException::withMessages(['note' => "Proyek ini sudah {$project->status->value} — tidak bisa menambah pekerjaan."]);
        }

        if ($project->addenda()->whereNotIn('status', QuotationStatus::closedValues())->exists()) {
            throw ValidationException::withMessages(['note' => 'Masih ada RAB Tambahan proyek ini yang sedang berjalan.']);
        }

        return DB::transaction(function () use ($project, $note, $actor, $links, $photos) {
            $quotation = Quotation::create([
                'lead_id' => $project->lead_id,
                'type' => QuotationType::Proyek->value,
                'parent_quotation_id' => $project->quotation_id,
                'project_id' => $project->id,
                'status' => QuotationStatus::Diminta->value,
                'created_by' => $actor->id,
                'requested_by' => $actor->id,
                'request_note' => trim($note),
            ]);
            $attached = $this->attachReferences($quotation, $links, $photos, $actor);

            $this->notificationService->notifyRoles(
                ['ESTIMATOR'],
                'quotation_requested',
                'Permintaan RAB Tambahan',
                "{$actor->name} meminta RAB Tambahan untuk proyek \"{$project->name}\": ".trim($note).$attached,
                ['quotation_id' => $quotation->id, 'project_id' => $project->id],
            );

            return $quotation;
        });
    }

    /** The Estimator picks up a requested RAB — DIMINTA → DRAFT (Sprint 12 #7). */
    public function startDraft(Quotation $quotation, User $actor): Quotation
    {
        $this->assertStatus($quotation, QuotationStatus::Diminta, 'RAB ini tidak sedang menunggu disusun.');

        return DB::transaction(function () use ($quotation, $actor) {
            // The Estimator who builds it owns it from here (notifications, KPI).
            $quotation->update(['status' => QuotationStatus::Draft->value, 'created_by' => $actor->id]);
            $this->ensureDefaultPaymentTerms($quotation);

            $this->notificationService->notifyMany(
                [$quotation->requester],
                'quotation_started',
                "{$quotation->title()} Mulai Disusun",
                "{$actor->name} mulai menyusun {$quotation->title()} \"{$quotation->lead->client_name}\".",
                ['quotation_id' => $quotation->id],
            );

            return $quotation->fresh();
        });
    }

    /**
     * Sprint 12 decision #11 — the whole RAB as the Excel lays it out:
     * bagian pekerjaan with their items (dimensions P × T/L optional),
     * then discount and rounding. The builder sends everything on every
     * save. `items` without sections (pre-Sprint-12 callers) are stored
     * without a section ("Umum"). Totals are computed here, never trusted:
     * total_amount = rounded_total ?? items_total − discount. The payment
     * scheme's amounts follow the new total.
     *
     * @param  array{sections?: list<array{name: string, items: list<array>}>, items?: list<array>, discount_amount?: numeric|null, rounded_total?: numeric|null}  $data
     */
    public function saveRab(Quotation $quotation, array $data): Quotation
    {
        $this->assertStatus($quotation, QuotationStatus::Draft, 'Item RAB hanya bisa diubah selama quotation berstatus DRAFT.');

        $groups = isset($data['sections'])
            ? $data['sections']
            : [['name' => null, 'items' => $data['items'] ?? []]];

        return DB::transaction(function () use ($quotation, $groups, $data) {
            $quotation->items()->delete();
            $quotation->sections()->delete();

            $totalCents = 0;
            $sort = 0;

            foreach (array_values($groups) as $groupIndex => $group) {
                $section = filled($group['name'] ?? null)
                    ? $quotation->sections()->create(['name' => trim($group['name']), 'sort_order' => $groupIndex])
                    : null;

                foreach (array_values($group['items'] ?? []) as $item) {
                    // qty may be fractional (2,5 m²) — round the line to the cent.
                    $lineCents = (int) round((float) $item['qty'] * (float) $item['unit_price'] * 100);
                    $totalCents += $lineCents;

                    $quotation->items()->create([
                        'section_id' => $section?->id,
                        'description' => $item['description'],
                        'dim_length' => $item['dim_length'] ?? null,
                        'dim_width_height' => $item['dim_width_height'] ?? null,
                        'qty' => $item['qty'],
                        'unit_id' => $item['unit_id'],
                        'unit_price' => $item['unit_price'],
                        'total_price' => $lineCents / 100,
                        'sort_order' => $sort++,
                    ]);
                }
            }

            $discountCents = (int) round((float) ($data['discount_amount'] ?? 0) * 100);

            if ($discountCents > $totalCents) {
                throw ValidationException::withMessages(['discount_amount' => 'Diskon tidak boleh melebihi total RAB.']);
            }

            $rounded = isset($data['rounded_total']) && $data['rounded_total'] !== '' ? round((float) $data['rounded_total'], 2) : null;

            $quotation->update([
                'items_total' => $totalCents / 100,
                'discount_amount' => $discountCents / 100,
                'rounded_total' => $rounded,
                'total_amount' => $rounded ?? ($totalCents - $discountCents) / 100,
            ]);

            $this->recalculatePaymentTerms($quotation);

            // The RAB is being written — the design is now "Pembuatan Penawaran".
            $this->syncDesign($quotation, DesignService::EVENT_QUOTATION_DRAFTED);

            return $quotation->fresh(['items', 'sections', 'paymentTerms']);
        });
    }

    /**
     * Sprint 12 decision #12 — the DP/termin scheme (1–6 rows incl. DP,
     * D1). Percentages must add up to exactly 100; amounts are derived
     * from the total so they always add up to it. A date-triggered row
     * needs its date, a milestone-triggered one the milestone's name.
     *
     * @param  list<array{label: string, percentage: numeric, trigger: string, due_date?: ?string, milestone_name?: ?string}>  $terms
     */
    public function savePaymentTerms(Quotation $quotation, array $terms): Quotation
    {
        $this->assertStatus($quotation, QuotationStatus::Draft, 'Skema pembayaran hanya bisa diubah selama quotation berstatus DRAFT.');

        $terms = array_values($terms);

        if ($terms === [] || count($terms) > self::MAX_PAYMENT_TERMS) {
            throw ValidationException::withMessages(['terms' => 'Skema pembayaran berisi 1 sampai '.self::MAX_PAYMENT_TERMS.' baris (termasuk DP).']);
        }

        $percentHundredths = array_sum(array_map(fn ($term) => (int) round((float) $term['percentage'] * 100), $terms));

        if ($percentHundredths !== 10000) {
            throw ValidationException::withMessages(['terms' => 'Total persentase skema pembayaran harus tepat 100% (sekarang '.rtrim(rtrim(number_format($percentHundredths / 100, 2, ',', ''), '0'), ',').'%).']);
        }

        foreach ($terms as $index => $term) {
            $trigger = PaymentTermTrigger::from($term['trigger']);

            if ($trigger === PaymentTermTrigger::Tanggal && blank($term['due_date'] ?? null)) {
                throw ValidationException::withMessages(["terms.{$index}.due_date" => 'Tanggal jatuh tempo wajib diisi untuk termin bertanggal.']);
            }

            if ($trigger === PaymentTermTrigger::Milestone && blank($term['milestone_name'] ?? null)) {
                throw ValidationException::withMessages(["terms.{$index}.milestone_name" => 'Nama milestone pemicu wajib diisi.']);
            }
        }

        return DB::transaction(function () use ($quotation, $terms) {
            $quotation->paymentTerms()->delete();

            foreach ($terms as $index => $term) {
                $trigger = PaymentTermTrigger::from($term['trigger']);

                $quotation->paymentTerms()->create([
                    'sequence' => $index + 1,
                    'label' => trim($term['label']),
                    'percentage' => $term['percentage'],
                    'amount' => 0,
                    'trigger' => $trigger->value,
                    'due_date' => $trigger === PaymentTermTrigger::Tanggal ? $term['due_date'] : null,
                    'milestone_name' => $trigger === PaymentTermTrigger::Milestone ? trim($term['milestone_name']) : null,
                ]);
            }

            $this->recalculatePaymentTerms($quotation);

            return $quotation->fresh('paymentTerms');
        });
    }

    /**
     * Pre-Sprint-12 entry point: a flat item list without sections,
     * discount or rounding (DemoDataSeeder, older tests). Same rules as saveRab().
     */
    public function replaceItems(Quotation $quotation, array $items): Quotation
    {
        return $this->saveRab($quotation, ['items' => $items]);
    }

    /**
     * Estimator hands the draft to PM / Asisten PM for the item review
     * (Sprint 12 #7) — SUBMITTED now means "waiting for PM".
     */
    public function submit(Quotation $quotation): Quotation
    {
        if ($quotation->status !== QuotationStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => 'Quotation hanya bisa disubmit dari status DRAFT.',
            ]);
        }

        if ($quotation->items()->count() === 0) {
            throw ValidationException::withMessages([
                'items' => 'Tambahkan minimal satu item RAB sebelum submit.',
            ]);
        }

        return DB::transaction(function () use ($quotation) {
            // Sprint 12 #12: a RAB always carries a payment scheme.
            $this->ensureDefaultPaymentTerms($quotation);
            $quotation->update(['status' => QuotationStatus::Submitted->value]);

            $this->syncDesign($quotation, DesignService::EVENT_QUOTATION_DRAFTED);

            $this->notificationService->notifyRoles(
                self::REVIEW_ROLES[QuotationItemReview::STAGE_PM],
                'quotation_submitted',
                "{$this->typeLabel($quotation)} Menunggu Review",
                "{$this->typeLabel($quotation)} \"{$quotation->lead->client_name}\" versi {$quotation->version} (".$this->rupiah($quotation->total_amount).') menunggu review item oleh PM / Asisten PM.',
                ['quotation_id' => $quotation->id],
            );

            return $quotation->fresh();
        });
    }

    /**
     * Sprint 12 decision #8 — the item review. The stage follows the
     * status: SUBMITTED is the PM / Asisten PM's, WAITING_CEO the CEO's
     * (so the CEO can never decide before the PM). Every item gets ✔ OK
     * or ✘ SALAH (a ✘ needs a note) at the PM stage; the CEO may mark the
     * items they object to. "approve" needs no ✘; "return" needs a ✘ or a
     * note and closes the version back to DRAFT for the Estimator. PM's
     * approval of a RAB Proyek moves it on to the CEO; otherwise the RAB
     * is approved internally.
     *
     * @param  array{decision: string, note?: ?string, items?: list<array{item_id: int, verdict: string, note?: ?string}>}  $data
     */
    public function review(Quotation $quotation, array $data, User $actor): Quotation
    {
        $stage = $this->reviewStage($quotation, $actor);
        $decision = $data['decision'];
        $note = filled($data['note'] ?? null) ? trim($data['note']) : null;
        $marks = collect($data['items'] ?? [])->keyBy(fn (array $mark) => (int) $mark['item_id']);
        $items = $quotation->items()->with('section:id,name')->get()->keyBy('id');

        if ($marks->keys()->diff($items->keys())->isNotEmpty()) {
            throw ValidationException::withMessages(['items' => 'Ada item yang bukan bagian dari RAB ini.']);
        }

        if ($stage === QuotationItemReview::STAGE_PM && $marks->count() !== $items->count()) {
            throw ValidationException::withMessages(['items' => 'Tandai semua item RAB (✔ cocok / ✘ kurang cocok) sebelum memutuskan.']);
        }

        foreach ($marks as $itemId => $mark) {
            if ($mark['verdict'] === QuotationItemReview::VERDICT_SALAH && blank($mark['note'] ?? null)) {
                throw ValidationException::withMessages(["items.{$itemId}" => "Catatan wajib diisi untuk item ✘ \"{$items[$itemId]->description}\"."]);
            }
        }

        $wrong = $marks->where('verdict', QuotationItemReview::VERDICT_SALAH)->count();

        if ($decision === 'approve' && $wrong > 0) {
            throw ValidationException::withMessages(['decision' => 'RAB dengan item ✘ tidak bisa disetujui — kembalikan ke Estimator.']);
        }

        if ($decision === 'return' && $wrong === 0 && $note === null) {
            throw ValidationException::withMessages(['note' => 'Tandai item yang salah (✘) atau tulis catatan alasan RAB dikembalikan.']);
        }

        return DB::transaction(function () use ($quotation, $stage, $decision, $note, $marks, $items, $wrong, $actor) {
            // Serialize decisions on one quotation — a double-submitted click
            // must not record two reviews or close one version twice.
            Quotation::whereKey($quotation->getKey())->lockForUpdate()->first();
            $quotation->refresh();

            if ($this->reviewStage($quotation, $actor) !== $stage) {
                throw ValidationException::withMessages(['status' => 'RAB ini sudah diputuskan oleh reviewer lain.']);
            }

            $oldStatus = $quotation->status;
            $version = $quotation->version;

            foreach ($marks as $itemId => $mark) {
                QuotationItemReview::create([
                    'quotation_id' => $quotation->id,
                    'version' => $version,
                    'quotation_item_id' => $itemId,
                    'item_description' => $items[$itemId]->description,
                    'section_name' => $items[$itemId]->section?->name,
                    'stage' => $stage,
                    'reviewer_id' => $actor->id,
                    'verdict' => $mark['verdict'],
                    'note' => filled($mark['note'] ?? null) ? trim($mark['note']) : null,
                ]);
            }

            $summary = $note ?? "{$wrong} item ditandai ✘.";

            QuotationApproval::create([
                'quotation_id' => $quotation->id,
                'version' => $version,
                'approver_id' => $actor->id,
                'approver_role' => $stage,
                'status' => $decision === 'approve' ? 'APPROVED' : 'REJECTED',
                'note' => $decision === 'approve' ? $note : $summary,
            ]);

            if ($decision === 'return') {
                $this->closeVersion($quotation, $stage, $actor, $summary);
            } else {
                $next = $stage === QuotationItemReview::STAGE_PM && $quotation->type === QuotationType::Proyek
                    ? QuotationStatus::WaitingCeo
                    : QuotationStatus::ApprovedInternal;
                $quotation->update(['status' => $next->value]);
            }

            // PRD §9.4 "approval quotation" — audit trail.
            $this->auditLogService->record(
                'quotation.'.strtolower($stage).'_'.($decision === 'approve' ? 'approved' : 'returned'),
                $quotation,
                ['status' => $oldStatus, 'version' => $version],
                ['status' => $quotation->status, 'version' => $quotation->version, 'total_amount' => $quotation->total_amount, 'items_wrong' => $wrong, 'note' => $note],
                $actor,
            );

            $this->notifyReview($quotation, $stage, $decision, $summary, $actor, $version);

            return $quotation->fresh();
        });
    }

    /** Sprint 12 #10 — the Estimator hands the internally approved RAB to Marketing. */
    public function sendToMarketing(Quotation $quotation, User $actor): Quotation
    {
        return $this->advance($quotation, QuotationStatus::ApprovedInternal, QuotationStatus::ReadyToSend, $actor, 'quotation.sent_to_marketing',
            'RAB ini belum disetujui internal — belum bisa dikirim ke Marketing.',
            function (Quotation $quotation) use ($actor) {
                $quotation->loadMissing(['lead.assignee', 'requester']);
                $recipients = collect([$quotation->lead->assignee, $quotation->requester])->filter()->unique('id');

                if ($recipients->isEmpty()) {
                    $this->notificationService->notifyRoles(['MARKETING'], 'quotation_ready_to_send', ...$this->readyToSendMessage($quotation, $actor));
                } else {
                    $this->notificationService->notifyMany($recipients, 'quotation_ready_to_send', ...$this->readyToSendMessage($quotation, $actor));
                }
            });
    }

    /**
     * Marketing sends the final RAB to the client — the offer's validity
     * period starts here (PRD §4.3 "default 14 hari dari tanggal kirim")
     * and the version gets its public link (decision #13), which Marketing
     * copies or sends over WhatsApp.
     */
    public function sendToClient(Quotation $quotation, User $actor): Quotation
    {
        return $this->advance($quotation, QuotationStatus::ReadyToSend, QuotationStatus::SentToClient, $actor, 'quotation.sent_to_client',
            'RAB ini belum dikirim Estimator ke Marketing.',
            function (Quotation $quotation) use ($actor) {
                // Sprint 12 #13 — the client's link, for this version only.
                $quotation->shareLinks()->create([
                    'version' => $quotation->version,
                    'token' => Str::random(QuotationShareLink::TOKEN_LENGTH),
                    'sent_by' => $actor->id,
                ]);

                $now = now();
                $quotation->update([
                    // Sprint 15 K2 — the offer letter's number, for this version.
                    'letter_number' => $quotation->letter_number ?? $this->letterNumbers->next(LetterNumberService::OFFER),
                    'valid_until' => now('Asia/Jakarta')->startOfDay()->addDays(self::VALIDITY_DAYS)->toDateString(),
                    'first_sent_at' => $quotation->first_sent_at ?? $now,
                    'sent_at' => $now,
                ]);

                $this->syncDesign($quotation, DesignService::EVENT_QUOTATION_SENT);
            });
    }

    /**
     * Marketing drops a RAB that is still running (client changed their
     * mind, wrong request) — a reason is required. A RAB Jasa Survey
     * releases its survey, so a new one can be asked for.
     */
    public function cancel(Quotation $quotation, User $actor, string $reason): Quotation
    {
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Alasan pembatalan wajib diisi.']);
        }

        return DB::transaction(function () use ($quotation, $actor, $reason) {
            Quotation::whereKey($quotation->getKey())->lockForUpdate()->first();
            $quotation->refresh();

            if (! $quotation->status->isOpen()) {
                throw ValidationException::withMessages(['status' => 'RAB ini sudah selesai atau dibatalkan.']);
            }

            $oldStatus = $quotation->status;
            $quotation->update(['status' => QuotationStatus::Cancelled->value]);
            LeadSurvey::where('quotation_id', $quotation->id)->update(['quotation_id' => null]);

            $this->auditLogService->record('quotation.cancelled', $quotation, ['status' => $oldStatus], ['status' => $quotation->status, 'reason' => trim($reason)], $actor);

            $quotation->loadMissing(['creator', 'lead']);
            $this->notificationService->notifyMany(
                collect([$quotation->creator])->filter()->reject(fn (User $user) => $user->is($actor)),
                'quotation_cancelled',
                "{$this->typeLabel($quotation)} Dibatalkan",
                "{$actor->name} membatalkan {$this->typeLabel($quotation)} \"{$quotation->lead->client_name}\": ".trim($reason),
                ['quotation_id' => $quotation->id],
            );

            return $quotation->fresh();
        });
    }

    /**
     * PRD §6.2 "SENT TO CLIENT → REJECTED (klien) → DRAFT (revisi)": the
     * client turned the offer down but wants a revised one (a client who
     * walks away is a LOST lead instead). Recorded by CEO/Marketing — the
     * same people who confirm the deal — as a CLIENT approval row, then
     * the version is closed and the Estimator revises a new one.
     */
    public function clientReject(Quotation $quotation, User $actor, ?string $note): Quotation
    {
        $this->assertStatus($quotation, QuotationStatus::SentToClient, 'Penolakan klien hanya bisa dicatat saat penawaran sudah dikirim ke klien.');

        if (blank($note)) {
            throw ValidationException::withMessages(['note' => 'Alasan penolakan klien wajib diisi.']);
        }

        return DB::transaction(function () use ($quotation, $actor, $note) {
            Quotation::whereKey($quotation->getKey())->lockForUpdate()->first();
            $quotation->refresh();
            $this->assertStatus($quotation, QuotationStatus::SentToClient, 'Penolakan klien hanya bisa dicatat saat penawaran sudah dikirim ke klien.');

            $old = ['status' => $quotation->status, 'version' => $quotation->version, 'valid_until' => $quotation->valid_until];
            $oldVersion = $quotation->version;

            QuotationApproval::create([
                'quotation_id' => $quotation->id,
                'version' => $quotation->version,
                'approver_id' => $actor->id,
                'approver_role' => 'CLIENT',
                'status' => 'REJECTED',
                'note' => $note,
            ]);

            $this->closeVersion($quotation, 'CLIENT', $actor, $note);
            $this->syncDesign($quotation, DesignService::EVENT_CLIENT_REJECTED);

            $this->auditLogService->record('quotation.client_rejected', $quotation, $old, [
                'status' => $quotation->status,
                'version' => $quotation->version,
                'valid_until' => $quotation->valid_until,
                'total_amount' => $quotation->total_amount,
                'note' => $note,
            ], $actor);

            $quotation->loadMissing(['creator', 'lead.assignee']);
            $this->notificationService->notifyMany(
                collect([$quotation->creator, $quotation->lead->assignee])->filter()->unique('id')->reject(fn (User $user) => $user->is($actor)),
                'quotation_rejected',
                'Penawaran Ditolak Klien',
                "Klien menolak penawaran \"{$quotation->lead->client_name}\" versi {$oldVersion} — quotation kembali ke DRAFT sebagai versi {$quotation->version} untuk direvisi: {$note}",
                ['quotation_id' => $quotation->id],
            );

            return $quotation->fresh();
        });
    }

    /**
     * Sprint 12 decisions #13–#14 — what the public link shows:
     * - `approved`: this version was approved by the client;
     * - `outdated`: a newer version exists ("Penawaran ini sudah diperbarui");
     * - `unavailable`: the offer is no longer on the table (cancelled, or
     *   pulled back for a revision);
     * - `expired`: past `valid_until` ("hubungi Marketing");
     * - `open`: the client may approve.
     */
    public function publicState(QuotationShareLink $link): string
    {
        $quotation = $link->quotation;

        return match (true) {
            $link->version !== $quotation->version => 'outdated',
            $quotation->status === QuotationStatus::ClientApproved => 'approved',
            $quotation->status !== QuotationStatus::SentToClient => 'unavailable',
            $quotation->valid_until !== null && $quotation->valid_until->lt(now('Asia/Jakarta')->startOfDay()) => 'expired',
            default => 'open',
        };
    }

    /**
     * Sprint 12 decision #13 — the client approves the RAB (and its payment
     * scheme) through the public link, after ticking "Saya telah membaca dan
     * menyetujui penawaran ini". No internal user acts here: the audit row
     * carries the client's IP and device, the approval is stamped on the
     * quotation, and QuotationClientApproved hands over to the next step
     * (lead closing for a RAB Proyek; survey payment / design / Buka Proyek
     * in later sub-plans).
     */
    public function clientApprove(QuotationShareLink $link, bool $agreed, ?string $ip, ?string $userAgent): Quotation
    {
        if (! $agreed) {
            throw ValidationException::withMessages(['agree' => 'Centang pernyataan persetujuan terlebih dahulu.']);
        }

        return DB::transaction(function () use ($link, $ip, $userAgent) {
            $quotation = Quotation::whereKey($link->quotation_id)->lockForUpdate()->firstOrFail();
            $link->setRelation('quotation', $quotation);

            $state = $this->publicState($link);

            if ($state !== 'open') {
                throw ValidationException::withMessages(['agree' => match ($state) {
                    'approved' => 'Penawaran ini sudah disetujui.',
                    'outdated' => 'Penawaran ini sudah diperbarui — minta link terbaru ke Marketing kami.',
                    'expired' => 'Masa berlaku penawaran ini sudah habis — silakan hubungi Marketing kami.',
                    default => 'Penawaran ini sudah tidak berlaku.',
                }]);
            }

            $quotation->update([
                'status' => QuotationStatus::ClientApproved->value,
                'client_approved_at' => now(),
                'client_approved_ip' => $ip,
                'client_approved_user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 500),
                'client_approved_link_id' => $link->id,
            ]);

            // PRD §9.4 — the client's acceptance is the final quotation approval.
            $this->auditLogService->record('quotation.client_approved', $quotation, ['status' => QuotationStatus::SentToClient], [
                'status' => $quotation->status,
                'version' => $quotation->version,
                'total_amount' => $quotation->total_amount,
                'via' => 'link klien',
                'user_agent' => $quotation->client_approved_user_agent,
            ]);

            $quotation->loadMissing(['creator', 'requester', 'lead.assignee', 'lead']);
            $this->notificationService->notifyMany(
                collect([$quotation->creator, $quotation->requester, $quotation->lead->assignee, $link->sender])->filter()->unique('id'),
                'quotation_client_approved',
                "{$this->typeLabel($quotation)} Disetujui Klien",
                "Klien \"{$quotation->lead->client_name}\" menyetujui {$this->typeLabel($quotation)} versi {$quotation->version} (".$this->rupiah($quotation->total_amount).') lewat link penawaran.',
                ['quotation_id' => $quotation->id, 'lead_id' => $quotation->lead_id],
            );

            QuotationClientApproved::dispatch($quotation, $link);

            return $quotation->fresh();
        });
    }

    /**
     * Sprint 14 Sub 01 — store a request's reference links and photos
     * (validated by the Form Request: http/https, counts, image types and
     * sizes). Photos go to the private disk, one folder per quotation.
     * Returns the " (+2 foto, 1 link)" suffix for the Estimator's notification.
     *
     * @param  list<string>  $links
     * @param  list<UploadedFile>  $photos
     */
    private function attachReferences(Quotation $quotation, array $links, array $photos, User $actor): string
    {
        $links = array_values(array_unique(array_filter(array_map('trim', $links))));

        foreach ($links as $url) {
            $quotation->references()->create([
                'kind' => QuotationReference::KIND_LINK,
                'url' => $url,
                'uploaded_by' => $actor->id,
            ]);
        }

        foreach ($photos as $photo) {
            $quotation->references()->create([
                'kind' => QuotationReference::KIND_PHOTO,
                'path' => $photo->store("quotation-references/{$quotation->id}", QuotationReference::DISK),
                'original_name' => mb_substr($photo->getClientOriginalName(), 0, 255),
                'size' => $photo->getSize(),
                'uploaded_by' => $actor->id,
            ]);
        }

        $parts = array_filter([
            $photos ? count($photos).' foto' : null,
            $links ? count($links).' link' : null,
        ]);

        return $parts ? ' (+'.implode(', ', $parts).')' : '';
    }

    /**
     * Whose turn it is, checked against the actor: PM / Asisten PM on
     * SUBMITTED, CEO on WAITING_CEO (SUPERADMIN acts as either).
     */
    public function reviewStage(Quotation $quotation, User $actor): string
    {
        $stage = match ($quotation->status) {
            QuotationStatus::Submitted => QuotationItemReview::STAGE_PM,
            QuotationStatus::WaitingCeo => QuotationItemReview::STAGE_CEO,
            default => throw ValidationException::withMessages(['status' => 'RAB ini tidak sedang menunggu review.']),
        };

        if (! $actor->hasAnyRole([...self::REVIEW_ROLES[$stage], 'SUPERADMIN'])) {
            throw ValidationException::withMessages(['status' => $stage === QuotationItemReview::STAGE_PM
                ? 'RAB ini menunggu review PM / Asisten PM terlebih dahulu.'
                : 'RAB ini menunggu keputusan CEO.']);
        }

        return $stage;
    }

    /**
     * Sprint 15 K4 — the RAB's "Catatan" for the client, written by the
     * Estimator while the version is a DRAFT. Empty = the type's default
     * from Pengaturan Situs (SiteSetting::defaultNoteFor()).
     */
    public function saveClientNotes(Quotation $quotation, ?string $notes): Quotation
    {
        $this->assertStatus($quotation, QuotationStatus::Draft, 'Catatan hanya bisa diubah selama RAB berstatus DRAFT.');

        $quotation->update(['client_notes' => trim((string) $notes) ?: null]);

        return $quotation;
    }

    /** One locked, audited status step (sendToMarketing / sendToClient). */
    private function advance(Quotation $quotation, QuotationStatus $from, QuotationStatus $to, User $actor, string $action, string $notReady, callable $after): Quotation
    {
        $this->assertStatus($quotation, $from, $notReady);

        return DB::transaction(function () use ($quotation, $from, $to, $actor, $action, $notReady, $after) {
            Quotation::whereKey($quotation->getKey())->lockForUpdate()->first();
            $quotation->refresh();
            $this->assertStatus($quotation, $from, $notReady);

            $quotation->update(['status' => $to->value]);
            $after($quotation);

            $this->auditLogService->record($action, $quotation, ['status' => $from], [
                'status' => $quotation->status,
                'total_amount' => $quotation->total_amount,
                'valid_until' => $quotation->valid_until,
            ], $actor);

            return $quotation->fresh();
        });
    }

    /** @return array{0: string, 1: string, 2: array<string, int>} title, message, metadata */
    private function readyToSendMessage(Quotation $quotation, User $actor): array
    {
        return [
            "{$this->typeLabel($quotation)} Siap Dikirim",
            "{$actor->name} mengirim {$this->typeLabel($quotation)} final \"{$quotation->lead->client_name}\" (".$this->rupiah($quotation->total_amount).') — silakan kirim ke klien.',
            ['quotation_id' => $quotation->id],
        ];
    }

    /**
     * PRD §4.3 "Versi Revisi: Sistem menyimpan riwayat revisi quotation".
     * Freezes the rejected version — its items and total, why and by whom
     * it was turned down — into an append-only QuotationRevision, then
     * reopens the quotation as the next version's DRAFT for the Estimator.
     * The validity period goes with the old version: a new one only
     * starts when the revised offer is sent again.
     */
    private function closeVersion(Quotation $quotation, string $gate, User $actor, string $note): void
    {
        QuotationRevision::create([
            'quotation_id' => $quotation->id,
            'version' => $quotation->version,
            'total_amount' => $quotation->total_amount,
            'items' => $quotation->items()->with('section:id,name')->get()->map(fn (QuotationItem $item) => [
                // Sprint 12: the bagian pekerjaan and dimensions travel with the line.
                'section' => $item->section?->name,
                'description' => $item->description,
                'dim_length' => $item->dim_length,
                'dim_width_height' => $item->dim_width_height,
                'qty' => (float) $item->qty,
                // Snapshots taken before Master Satuan (Sprint 11) hold a free-text `unit` instead.
                'unit_code' => $item->unit?->code,
                'unit_price' => $item->unit_price,
                'total_price' => $item->total_price,
            ])->all(),
            // Sprint 12 #11–#12: totals and the payment scheme of the closed version.
            'details' => [
                'items_total' => $quotation->items_total,
                'discount_amount' => $quotation->discount_amount,
                'rounded_total' => $quotation->rounded_total,
                'payment_terms' => $quotation->paymentTerms()->get()->map(fn (QuotationPaymentTerm $term) => [
                    'sequence' => $term->sequence,
                    'label' => $term->label,
                    'percentage' => $term->percentage,
                    'amount' => $term->amount,
                    'trigger' => $term->trigger->value,
                    'due_date' => $term->due_date?->toDateString(),
                    'milestone_name' => $term->milestone_name,
                ])->all(),
            ],
            'reason' => QuotationRevision::reasonFor($gate),
            'note' => $note,
            'closed_by' => $actor->id,
        ]);

        $quotation->update([
            'status' => QuotationStatus::Draft->value,
            'version' => $quotation->version + 1,
            'valid_until' => null,
            // A revised offer is a new letter — it gets its own number when sent.
            'letter_number' => null,
        ]);
    }

    /**
     * D4: a Jasa Survey / Jasa Desain RAB is paid 100% upfront; a project
     * RAB starts with the same single row until the Estimator drafts its
     * DP/termin scheme. Only when the quotation has no scheme yet.
     */
    private function ensureDefaultPaymentTerms(Quotation $quotation): void
    {
        if ($quotation->paymentTerms()->exists()) {
            return;
        }

        $quotation->paymentTerms()->create([
            'sequence' => 1,
            'label' => 'Pembayaran penuh',
            'percentage' => 100,
            'amount' => $quotation->total_amount ?? 0,
            'trigger' => PaymentTermTrigger::DiMuka->value,
        ]);
    }

    /** amount = total × percentage, in cents; the last row takes the remainder so the rows sum to the total exactly. */
    private function recalculatePaymentTerms(Quotation $quotation): void
    {
        $terms = $quotation->paymentTerms()->get();

        if ($terms->isEmpty()) {
            return;
        }

        $totalCents = (int) round((float) $quotation->fresh()->total_amount * 100);
        $assigned = 0;

        foreach ($terms as $index => $term) {
            $cents = $index === $terms->count() - 1
                ? $totalCents - $assigned
                : (int) floor($totalCents * (float) $term->percentage / 100);
            $assigned += $cents;

            $term->update(['amount' => $cents / 100]);
        }
    }

    /** The design pipeline only follows the project RAB (Jasa Survey / Desain quotations don't move it). */
    private function syncDesign(Quotation $quotation, string $event): void
    {
        // An in-memory row created without `type` is PROYEK (the column default).
        if (($quotation->type ?? QuotationType::Proyek) === QuotationType::Proyek) {
            $this->designService()->syncWithPipeline($quotation->lead_id, $event);
        }
    }

    private function assertStatus(Quotation $quotation, QuotationStatus $required, string $message): void
    {
        if ($quotation->status !== $required) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    /**
     * Sprint 12: a returned RAB goes back to the Estimator who built it;
     * PM's approval of a RAB Proyek hands it to the CEO; an internal
     * approval tells the Estimator to send it on to Marketing.
     */
    private function notifyReview(Quotation $quotation, string $stage, string $decision, string $summary, User $actor, int $reviewedVersion): void
    {
        $quotation->loadMissing(['creator', 'lead']);
        $client = $quotation->lead->client_name;
        $label = $this->typeLabel($quotation);
        $metadata = ['quotation_id' => $quotation->id];
        $estimator = collect([$quotation->creator])->filter()->reject(fn (User $user) => $user->is($actor));
        $by = $stage === QuotationItemReview::STAGE_PM ? 'PM / Asisten PM' : 'CEO';

        if ($decision === 'return') {
            $this->notificationService->notifyMany(
                $estimator,
                'quotation_rejected',
                "{$label} Dikembalikan",
                "{$label} \"{$client}\" versi {$reviewedVersion} dikembalikan {$by} ({$actor->name}) dan dibuka lagi sebagai versi {$quotation->version}: {$summary}",
                $metadata,
            );

            return;
        }

        if ($quotation->status === QuotationStatus::WaitingCeo) {
            $this->notificationService->notifyRoles(
                self::REVIEW_ROLES[QuotationItemReview::STAGE_CEO],
                'quotation_awaiting_ceo',
                'RAB Proyek Menunggu Approval CEO',
                "RAB Proyek \"{$client}\" (".$this->rupiah($quotation->total_amount).") sudah di-ACC {$actor->name} dan menunggu keputusan Anda.",
                $metadata,
            );

            return;
        }

        $this->notificationService->notifyMany(
            $estimator,
            'quotation_approved',
            "{$label} Disetujui",
            "{$label} \"{$client}\" disetujui {$by} — kirim RAB final ke Marketing.",
            $metadata,
        );
    }

    private function typeLabel(Quotation $quotation): string
    {
        return $quotation->title();
    }

    /**
     * Resolved on demand rather than constructor-injected: DesignService
     * already depends on this class (clientAcc() opens the quotation), so
     * injecting both ways would be a circular dependency.
     */
    private function designService(): DesignService
    {
        return app(DesignService::class);
    }

    private function rupiah(string|float|null $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }
}
