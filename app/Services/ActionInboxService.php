<?php

namespace App\Services;

use App\Enums\DesignStatus;
use App\Enums\InvoiceStatus;
use App\Enums\KpiIndicatorSource;
use App\Enums\KpiPeriodStatus;
use App\Enums\LeadStatus;
use App\Enums\LeadSurveyStatus;
use App\Enums\MaterialRequestStatus;
use App\Enums\OvertimeStatus;
use App\Enums\ProjectPhase;
use App\Enums\QaStatus;
use App\Enums\QuotationStatus;
use App\Enums\QuotationType;
use App\Enums\ReviewStatus;
use App\Enums\SupervisorPaymentKind;
use App\Enums\SupervisorPaymentStatus;
use App\Enums\TaskStatus;
use App\Enums\TerminStatus;
use App\Enums\WeeklyReportStatus;
use App\Enums\WorkingDrawingStatus;
use App\Models\BudgetOverrunRequest;
use App\Models\Design;
use App\Models\Invoice;
use App\Models\KpiScore;
use App\Models\Lead;
use App\Models\LeadSurvey;
use App\Models\OvertimeRequest;
use App\Models\PerformanceReview;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\ProjectOpening;
use App\Models\QaForm;
use App\Models\Quotation;
use App\Models\SalaryChange;
use App\Models\SupervisorPayment;
use App\Models\Task;
use App\Models\Termin;
use App\Models\User;
use App\Models\WorkingDrawing;
use App\Support\DailyFormSchedule;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 13 #4/#5 — "Perlu Tindakan": what is waiting for THIS user right
 * now, one group per queue. Every query reuses the scope / status filter
 * of the list page the group links to, so a group's count is exactly what
 * that page (with the same filter) lists for the same user. The badges on
 * the sidebar menus are these counts summed per menu route.
 *
 * Read-only: nothing here changes a status. Counts are cached 60 s per
 * user (D4) and the cache is dropped after every write request the user
 * makes (ForgetActionInbox middleware) — processing an item updates your
 * own badges at once; other people's badges follow within a minute.
 *
 * @phpstan-type InboxItem array{id: string, title: string, subtitle: string|null, at: string|null, href: string}
 * @phpstan-type InboxGroup array{key: string, label: string, description: string, icon: string, count: int, routeName: string, href: string, items: list<InboxItem>}
 */
class ActionInboxService
{
    /** Items shown per group — the rest sit behind "Lihat semua". */
    public const ITEMS_PER_GROUP = 5;

    public const CACHE_SECONDS = 60;

    /**
     * Every queue waiting for this user, busiest first.
     *
     * @return list<InboxGroup>
     */
    public function for(User $user, bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::cacheKey($user));
        }

        return Cache::remember(self::cacheKey($user), self::CACHE_SECONDS, fn () => $this->build($user));
    }

    /**
     * Menu route name → items waiting there (the sidebar badges). A hub
     * sums its tabs client-side.
     *
     * @return array<string, int>
     */
    public function badges(User $user): array
    {
        $badges = [];

        foreach ($this->for($user) as $group) {
            $badges[$group['routeName']] = ($badges[$group['routeName']] ?? 0) + $group['count'];
        }

        return $badges;
    }

    public static function forget(User $user): void
    {
        Cache::forget(self::cacheKey($user));
    }

    /**
     * Sprint 17 Sub 03 — something entered or left a Marketing queue
     * without that Marketing making the request (the client approving on
     * the public link, a scheduled job, a colleague issuing the invoice):
     * drop the lead owner's cache, or every Marketing's when the lead has
     * no owner (those items show in every Marketing's queue). After the
     * surrounding transaction commits, so the rebuilt queue sees the change.
     */
    public static function forgetMarketingOf(?User $assignee): void
    {
        DB::afterCommit(function () use ($assignee) {
            $users = $assignee ? [$assignee] : User::role('MARKETING')->get(['id']);

            foreach ($users as $user) {
                self::forget($user);
            }
        });
    }

    private static function cacheKey(User $user): string
    {
        return "inbox:{$user->id}";
    }

    /** @return list<InboxGroup> */
    private function build(User $user): array
    {
        // A technical admin has no business queue of its own (god-mode
        // access is for fixing things, not for working them).
        if ($user->hasRole('SUPERADMIN')) {
            return [];
        }

        $groups = [];

        if ($user->hasRole('CEO')) {
            $groups[] = $this->budgetOverruns();
            $groups[] = $this->salaryChanges();
            $groups[] = $this->submittedReviews();
        }

        if ($user->hasAnyRole(['PM', 'ASISTEN_PM'])) {
            $groups[] = $this->quotations($user, QuotationStatus::Submitted, 'quotation-pm', 'RAB menunggu review PM', 'Tandai ✔/✘ tiap item RAB yang diajukan Estimator.');
            $groups[] = $this->materialRequests($user, MaterialRequestStatus::MenungguPm, 'material-pm', 'Pengajuan barang menunggu ACC', 'Pengajuan dari tukang & Estimator di proyek Anda.');
            $groups[] = $this->rejectedQa($user);
        }

        if ($user->hasRole('PM')) {
            // Sprint 23 K13 — "Buka Proyek" is the approving PM's (route `role:PM`).
            $groups[] = $this->projectOpenings($user);
            // pmApprove is `role:PM` — the Asisten PM doesn't decide overtime.
            $groups[] = $this->overtime(OvertimeStatus::Pending, 'overtime-pm', 'Lembur menunggu ACC PM');
            // Sprint 24 — TM, Pengawas payments, weekly reports, "Berangkat ke Lokasi".
            $groups[] = $this->executionForPm($user);
        }

        if ($user->hasRole('ESTIMATOR')) {
            $groups[] = $this->quotations($user, QuotationStatus::Diminta, 'quotation-requested', 'RAB diminta Marketing', 'Ambil dan susun RAB yang diminta.');
            $groups[] = $this->returnedQuotations();
        }

        if ($user->hasRole('MARKETING')) {
            $groups[] = $this->dueFollowUps($user);
            $groups[] = $this->surveysReady($user);
            $groups[] = $this->surveysToSchedule($user);
            $groups[] = $this->quotations($user, QuotationStatus::ReadyToSend, 'quotation-send', 'RAB siap dikirim ke klien', 'Kirim link persetujuan ke klien.');
            // Sprint 23 K11 — the client said "Tidak Setuju" on the link.
            $groups[] = $this->quotations($user, QuotationStatus::ClientRejected, 'quotation-client-rejected', 'RAB ditolak klien', 'Tambahkan catatan yang kurang lalu teruskan ke Estimator.');
            $groups[] = $this->invoiceToIssue($user);
            $groups[] = $this->terminsToInvoice($user);
            $groups[] = $this->invoicesAwaitingProof($user);
        }

        if ($user->hasRole('FINANCE')) {
            $groups[] = $this->invoicesToVerify();
            $groups[] = $this->overtime(OvertimeStatus::PendingFinance, 'overtime-finance', 'Lembur menunggu ACC Finance');
            $groups[] = $this->overdueTermins();
            $groups[] = $this->supervisorPaymentsToPay();
        }

        if ($user->hasRole('KEPALA_DESAIN')) {
            $groups[] = $this->designsToAssign($user);
            $groups[] = $this->workingDrawingsToAssign();
        }

        // Sprint 18 Sub 06 — the architect's own work. Sprint 23 K20: revisions
        // are asked in the WhatsApp group now, so one queue until the client approves
        // (REVISI_DESAIN only on rows of the Sprint 12/22 flow).
        if ($user->hasRole('DESIGNER')) {
            $groups[] = $this->ownDesigns($user, [DesignStatus::Brief, DesignStatus::Desain, DesignStatus::RevisiDesain], 'design-work', 'Desain sedang Anda kerjakan', 'Simpan link grup WhatsApp & link desain, lalu tekan "Desain Disetujui Klien" begitu klien setuju di grup.');
            $groups[] = $this->ownWorkingDrawings($user);
        }

        if ($user->hasRole('LOGISTICS')) {
            $groups[] = $this->materialRequests($user, MaterialRequestStatus::Diajukan, 'material-logistics', 'Pengajuan barang menunggu Logistik', 'Cek katalog, setujui atau tolak.');
        }

        if ($user->hasRole('QA')) {
            $groups[] = $this->pendingQa($user);
            $groups[] = $this->meetingsForSupervisor($user);
            $groups[] = $this->executionForSupervisor($user);
        }

        if ($user->hasRole('HR')) {
            $groups[] = $this->returnedReviews();
            $groups[] = $this->missingKpiScores();
        }

        if ($user->hasRole('FIELD_STAFF')) {
            $groups[] = $this->tasksToday($user);
            $groups[] = $this->dailyFormsMissing($user);
        }

        $groups = array_values(array_filter($groups));
        usort($groups, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return $groups;
    }

    /**
     * One queue: count everything, show the first ITEMS_PER_GROUP (the
     * query's own order — oldest waiting first, the queue is worked front
     * to back). Null when empty, so empty queues never show.
     *
     * @param  Closure(mixed): array{id: int|string, title: string, subtitle?: string|null, at?: mixed, href: string}  $present
     * @return InboxGroup|null
     */
    private function group(string $key, string $label, string $description, string $icon, string $routeName, string $href, Builder $query, Closure $present): ?array
    {
        $count = (clone $query)->reorder()->count();

        if ($count === 0) {
            return null;
        }

        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'icon' => $icon,
            'count' => $count,
            'routeName' => $routeName,
            'href' => $href,
            'items' => $query->limit(self::ITEMS_PER_GROUP)->get()->map(function ($model) use ($key, $present) {
                $item = $present($model);
                $at = $item['at'] ?? null;

                return [
                    'id' => "{$key}-{$item['id']}",
                    'title' => $item['title'],
                    'subtitle' => $item['subtitle'] ?? null,
                    // Some tables keep raw timestamp strings (qa_forms has no Eloquent timestamps).
                    'at' => $at === null ? null : Carbon::parse($at)->toIso8601String(),
                    'href' => $item['href'],
                ];
            })->values()->all(),
        ];
    }

    private function rupiah(float|int|string|null $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }

    // ── Quotation (QuotationController@index: unscoped, ?status=) ────────

    private function quotations(User $user, QuotationStatus $status, string $key, string $label, string $description): ?array
    {
        return $this->group(
            $key, $label, $description, 'quotation', 'quotations.index',
            route('quotations.index', ['status' => $status->value]),
            Quotation::query()->with('lead:id,client_name')->byStatus($status->value)->oldest('updated_at'),
            fn (Quotation $q) => [
                'id' => $q->id,
                'title' => $q->lead?->client_name ?? "Quotation #{$q->id}",
                'subtitle' => "{$q->title()} v{$q->version} · {$this->rupiah($q->total_amount)}",
                'at' => $q->updated_at,
                'href' => route('quotations.show', $q),
            ],
        );
    }

    /** A version reopened as DRAFT after a ✘ review or a client rejection (QuotationService closes it into a revision). */
    private function returnedQuotations(): ?array
    {
        return $this->group(
            'quotation-returned', 'RAB dikembalikan untuk revisi', 'Perbaiki item yang ditandai ✘ lalu ajukan lagi.', 'quotation', 'quotations.index',
            route('quotations.index', ['status' => QuotationStatus::Draft->value]),
            Quotation::query()->with('lead:id,client_name')->byStatus(QuotationStatus::Draft->value)->where('version', '>', 1)->oldest('updated_at'),
            fn (Quotation $q) => [
                'id' => $q->id,
                'title' => $q->lead?->client_name ?? "Quotation #{$q->id}",
                'subtitle' => "{$q->title()} v{$q->version}",
                'at' => $q->updated_at,
                'href' => route('quotations.show', $q),
            ],
        );
    }

    // ── PM: Buka Proyek ──────────────────────────────────────────────────

    /**
     * Sprint 23 K13 — the same rows as this PM's "Buka Proyek" pop-up
     * (ProjectOpening::openableBy(): the RABs they approved, plus the ones
     * any PM may open). Each item opens its form on the project list.
     */
    private function projectOpenings(User $user): ?array
    {
        return $this->group(
            'project-opening', 'Proyek siap dibuka', 'Klien sudah menyetujui RAB Proyek — lengkapi data proyek lalu Buka Proyek.', 'project', 'projects.index',
            route('projects.index'),
            ProjectOpening::query()->waiting()->openableBy($user)->with(['lead:id,client_name', 'quotation:id,total_amount,version'])->oldest(),
            fn (ProjectOpening $opening) => [
                'id' => $opening->id,
                'title' => $opening->lead?->client_name ?? "RAB #{$opening->quotation_id}",
                'subtitle' => 'RAB Proyek v'.$opening->quotation?->version.' · '.$this->rupiah($opening->quotation?->total_amount),
                'at' => $opening->created_at,
                'href' => route('projects.index', ['opening' => $opening->id]),
            ],
        );
    }

    // ── CEO ──────────────────────────────────────────────────────────────

    /** Sprint 12 #28 — realisations over budget, decided from the project's Alokasi Dana tab. */
    private function budgetOverruns(): ?array
    {
        return $this->group(
            'budget-overrun', 'Realisasi melebihi anggaran', 'Setujui atau tolak pengajuan overrun dari PM.', 'budget', 'projects.index',
            route('projects.index'),
            BudgetOverrunRequest::query()->waiting()->with(['post:id,project_id,name', 'post.project:id,name', 'requester:id,name'])->oldest(),
            fn (BudgetOverrunRequest $overrun) => [
                'id' => $overrun->id,
                'title' => $overrun->post?->project?->name ?? 'Proyek',
                'subtitle' => "{$overrun->post?->name} · lebih {$this->rupiah($overrun->amount_over)}",
                'at' => $overrun->created_at,
                'href' => route('projects.show', ['project' => $overrun->post?->project_id, 'tab' => 'finance', 'view' => 'alokasi']),
            ],
        );
    }

    private function salaryChanges(): ?array
    {
        return $this->group(
            'salary-change', 'Perubahan gaji menunggu persetujuan', 'Diajukan SDM — gaji baru berlaku setelah disetujui CEO.', 'salary', 'hr.salary.index',
            route('hr.salary.index'),
            SalaryChange::query()->pending()->with('employee:id,name')->oldest(),
            fn (SalaryChange $change) => [
                'id' => $change->id,
                'title' => $change->employee?->name ?? 'Karyawan',
                'subtitle' => "{$this->rupiah($change->old_salary)} → {$this->rupiah($change->new_salary)}",
                'at' => $change->created_at,
                'href' => route('hr.salary.index'),
            ],
        );
    }

    private function submittedReviews(): ?array
    {
        return $this->group(
            'review-submitted', 'Evaluasi menunggu persetujuan', 'Evaluasi semester yang diajukan SDM.', 'review', 'hr.reviews.index',
            route('hr.reviews.index', ['status' => ReviewStatus::Submitted->value]),
            PerformanceReview::query()->where('status', ReviewStatus::Submitted->value)->with('employee:id,name')->oldest('submitted_at'),
            fn (PerformanceReview $review) => [
                'id' => $review->id,
                'title' => $review->employee?->name ?? 'Karyawan',
                'subtitle' => $review->periodLabel(),
                'at' => $review->submitted_at,
                'href' => route('hr.reviews.show', $review),
            ],
        );
    }

    // ── PM / Asisten PM / Logistik ───────────────────────────────────────

    /** MaterialRequestController@index — ProjectMaterial::visibleTo() + ?status=. */
    private function materialRequests(User $user, MaterialRequestStatus $status, string $key, string $label, string $description): ?array
    {
        return $this->group(
            $key, $label, $description, 'material', 'logistics.material-requests.index',
            route('logistics.material-requests.index', ['status' => $status->value]),
            ProjectMaterial::query()
                ->requested()
                ->visibleTo($user)
                ->where('request_status', $status->value)
                ->with(['project:id,name', 'requester:id,name', 'material:id,name'])
                ->orderByRaw('COALESCE(submitted_at, created_at) asc'),
            fn (ProjectMaterial $line) => [
                'id' => $line->id,
                'title' => $line->display_name,
                'subtitle' => trim(($line->project?->name ?? '').' · '.($line->requester?->name ?? ''), ' ·'),
                'at' => $line->submitted_at ?? $line->created_at,
                'href' => route('logistics.material-requests.index', ['status' => $status->value, 'project_id' => $line->project_id]),
            ],
        );
    }

    /** A rejected milestone QA the project's PM / Asisten PM must fix and resubmit (qa-forms.resubmit). */
    private function rejectedQa(User $user): ?array
    {
        return $this->group(
            'qa-rejected', 'Timeline ditolak Pengawas — perlu perbaikan', 'Perbaiki temuan Pengawas (QA/QC) lalu ajukan ulang dari tab Timeline proyek.', 'qa', 'projects.index',
            route('projects.index'),
            QaForm::query()
                ->where('status', QaStatus::Rejected->value)
                ->whereHas('project', fn (Builder $project) => $project->managedBy($user))
                ->with(['project:id,name', 'milestone:id,name'])
                ->oldest('reviewed_at'),
            fn (QaForm $form) => [
                'id' => $form->id,
                'title' => $form->project?->name ?? 'Proyek',
                'subtitle' => $form->milestone?->name,
                'at' => $form->reviewed_at,
                'href' => route('projects.show', ['project' => $form->project_id, 'tab' => 'milestone', 'view' => 'qc']),
            ],
        );
    }

    /** OvertimeController@index — unscoped for PM/Finance, ?status=. */
    private function overtime(OvertimeStatus $status, string $key, string $label): ?array
    {
        return $this->group(
            $key, $label, 'Setujui atau tolak pengajuan lembur tukang.', 'overtime', 'overtime.index',
            route('overtime.index', ['status' => $status->value]),
            OvertimeRequest::query()->byStatus($status->value)->with(['staff:id,name', 'project:id,name'])->oldest(),
            fn (OvertimeRequest $overtime) => [
                'id' => $overtime->id,
                'title' => $overtime->staff?->name ?? 'Tukang',
                'subtitle' => trim("{$overtime->hours} jam · ".($overtime->project?->name ?? '')),
                'at' => $overtime->created_at,
                'href' => route('overtime.index', ['status' => $status->value]),
            ],
        );
    }

    // ── Marketing ────────────────────────────────────────────────────────

    /** Open follow-ups due today or earlier on the Marketing's own live leads (DashboardController's rule). */
    private function dueFollowUps(User $user): ?array
    {
        $today = now('Asia/Jakarta')->toDateString();

        return $this->group(
            'follow-up', 'Follow-up jatuh tempo', 'Hubungi klien lalu catat hasil follow-up.', 'followup', 'crm.leads.index',
            route('crm.leads.index'),
            Lead::query()
                ->where('assigned_to', $user->id)
                ->whereNotIn('status', [LeadStatus::Lost->value, LeadStatus::Closing->value])
                ->whereHas('followUps', fn (Builder $q) => $q->pending()->whereDate('scheduled_date', '<=', $today))
                ->select(['id', 'client_name', 'status'])
                ->withNextFollowUp()
                ->orderBy('next_follow_up_date'),
            fn (Lead $lead) => [
                'id' => $lead->id,
                'title' => $lead->client_name,
                'subtitle' => 'Follow-up '.Carbon::parse($lead->next_follow_up_date)->translatedFormat('d M Y'),
                'at' => null,
                'href' => route('crm.leads.show', $lead),
            ],
        );
    }

    /**
     * Sprint 18 Sub 06 — paid surveys ("Survey Siap Berangkat", P1) on this
     * Marketing user's leads, until the survey is done or cancelled.
     */
    private function surveysReady(User $user): ?array
    {
        return $this->group(
            'survey-ready', 'Survey siap berangkat', 'Survey sudah dibayar klien — jadwalkan & berangkat.', 'followup', 'crm.leads.index',
            route('crm.leads.index'),
            LeadSurvey::query()
                ->where('status', LeadSurveyStatus::Siap->value)
                ->whereHas('lead', fn (Builder $q) => $q->where('assigned_to', $user->id))
                ->with('lead:id,client_name')
                ->orderBy('scheduled_at'),
            fn (LeadSurvey $survey) => [
                'id' => $survey->id,
                'title' => $survey->lead?->client_name ?? "Survey #{$survey->id}",
                'subtitle' => $survey->scheduled_at
                    ? 'Jadwal '.Carbon::parse($survey->scheduled_at)->translatedFormat('d M Y H:i')
                    : 'Belum dijadwalkan',
                'at' => $survey->updated_at,
                'href' => route('crm.leads.show', $survey->lead_id),
            ],
        );
    }

    /**
     * Sprint 19 — RAB Jasa Survey paid & verified, no survey scheduled on it
     * yet (Quotation::surveyToSchedule()). The item opens the RAB with its
     * "Jadwalkan Survey" dialog.
     */
    private function surveysToSchedule(User $user): ?array
    {
        return $this->group(
            'survey-schedule', 'Survey lunas — belum dijadwalkan', 'Pembayaran survey sudah diverifikasi Finance; tentukan jadwal survey.', 'followup', 'crm.leads.index',
            route('crm.leads.index'),
            Quotation::query()->surveyToSchedule($user)->with('lead:id,client_name')->oldest('id'),
            fn (Quotation $q) => [
                'id' => $q->id,
                'title' => $q->lead?->client_name ?? "Quotation #{$q->id}",
                'subtitle' => "{$q->title()} v{$q->version} · lunas",
                'at' => $q->updated_at,
                'href' => route('quotations.show', ['quotation' => $q->id, 'survey' => 'new']),
            ],
        );
    }

    /**
     * Sprint 17 Sub 03 (T3) — Jasa Survey / Jasa Desain RABs the client
     * approved, invoice not issued yet (Quotation::awaitingInvoice(), same
     * as the Quotation list's `awaiting_invoice` filter), oldest approval
     * first. Each item opens the RAB with its "Terbitkan Invoice" dialog.
     */
    private function invoiceToIssue(User $user): ?array
    {
        return $this->group(
            'quotation-invoice', 'RAB disetujui klien — terbitkan invoice', 'Klien sudah setuju; terbitkan invoice agar bisa dibayar.', 'invoice', 'quotations.index',
            route('quotations.index', ['awaiting_invoice' => 1]),
            Quotation::query()->awaitingInvoice($user)->with(['lead:id,client_name', 'paymentTerms'])->oldest('client_approved_at')->oldest('id'),
            fn (Quotation $q) => [
                'id' => $q->id,
                'title' => $q->lead?->client_name ?? "Quotation #{$q->id}",
                // Sprint 17 Sub 06 (K2) — a RAB Proyek bills its DP, not the whole RAB.
                'subtitle' => $q->type === QuotationType::Proyek
                    ? "DP {$q->title()} · {$this->rupiah($q->upfrontTerm()?->amount)}"
                    : "{$q->title()} v{$q->version} · {$this->rupiah($q->total_amount)}",
                'at' => $q->client_approved_at,
                'href' => route('quotations.show', ['quotation' => $q->id, 'action' => 'invoice']),
            ],
        );
    }

    /**
     * Sprint 17 Sub 07 — issued invoices still waiting for the client's
     * payment confirmation (incl. ones Finance sent back), nearest due date first.
     * The item opens the "Tandai Klien Sudah Bayar" dialog on the invoice list.
     */
    private function invoicesAwaitingProof(User $user): ?array
    {
        return $this->group(
            'invoice-proof', 'Invoice menunggu konfirmasi bayar', 'Setelah klien membayar, tandai sudah bayar (link bukti transfer bila ada) untuk diverifikasi Finance.', 'invoice', 'finance.invoices.index',
            route('finance.invoices.index', ['awaiting_proof' => 1]),
            Invoice::query()->awaitingProof($user)->withPaymentLabelRelations()->orderBy('due_date')->orderBy('id'),
            fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'title' => $invoice->lead?->client_name ?? $invoice->number,
                // Sprint 23 K12 — which payment ("Termin 2 dari 4 · 30%").
                'subtitle' => $invoice->reject_reason
                    ? "{$invoice->number} · {$invoice->paymentTypeLabel()} · ditolak Finance: {$invoice->reject_reason}"
                    : "{$invoice->number} · {$invoice->paymentTypeLabel()} · {$this->rupiah($invoice->amount)} · jatuh tempo ".$invoice->due_date?->translatedFormat('d M Y'),
                'at' => $invoice->rejected_at ?? $invoice->issued_at,
                'href' => route('finance.invoices.index', ['awaiting_proof' => 1, 'proof' => $invoice->id]),
            ],
        );
    }

    /**
     * Termins TerminService::remindInvoices() found due (trigger reached)
     * and nobody invoiced yet — on the Marketing's own leads, or on leads
     * without a Marketing (those were announced to every Marketing).
     */
    private function terminsToInvoice(User $user): ?array
    {
        return $this->group(
            'termin-invoice', 'Termin perlu diterbitkan invoice', 'Termin sudah waktunya ditagih — terbitkan invoice dari tab Finance proyek.', 'termin', 'projects.index',
            route('projects.index'),
            Termin::query()
                ->whereNotNull('invoice_reminded_at')
                ->whereNull('invoice_id')
                ->where('status', '!=', TerminStatus::Paid->value)
                ->whereHas('project', fn (Builder $project) => $project->whereHas('lead', fn (Builder $lead) => $lead
                    ->where(fn (Builder $q) => $q->where('assigned_to', $user->id)->orWhereNull('assigned_to'))))
                ->with('project:id,name')
                ->oldest('invoice_reminded_at'),
            fn (Termin $termin) => [
                'id' => $termin->id,
                'title' => $termin->project?->name ?? 'Proyek',
                'subtitle' => "Termin {$termin->termin_number} · {$this->rupiah($termin->amount)}",
                'at' => $termin->invoice_reminded_at,
                'href' => route('projects.show', ['project' => $termin->project_id, 'tab' => 'finance', 'view' => 'termin']),
            ],
        );
    }

    // ── Finance ──────────────────────────────────────────────────────────

    /** InvoiceController@verification — MENUNGGU_VERIFIKASI, oldest proof first. */
    private function invoicesToVerify(): ?array
    {
        return $this->group(
            'invoice-verify', 'Pembayaran menunggu konfirmasi', 'Cocokkan pembayaran dengan mutasi rekening.', 'invoice', 'finance.invoices.verification',
            route('finance.invoices.verification'),
            Invoice::query()->byStatus(InvoiceStatus::MenungguVerifikasi->value)->withPaymentLabelRelations()->orderBy('proof_submitted_at'),
            fn (Invoice $invoice) => [
                'id' => $invoice->id,
                // Sprint 23 K12b — "Menunggu Konfirmasi Pembayaran DP · Proyek Kopi Oz" (Invoice::confirmationLabel()).
                'title' => $invoice->confirmationLabel(),
                'subtitle' => trim($invoice->number.' · '.($invoice->lead?->client_name ?? '').' · '.$this->rupiah($invoice->amount), ' ·'),
                'at' => $invoice->proof_submitted_at,
                // Opens the Verifikasi dialog of this invoice.
                'href' => route('finance.invoices.verification', ['verify' => $invoice->id]),
            ],
        );
    }

    private function overdueTermins(): ?array
    {
        return $this->group(
            'termin-overdue', 'Termin lewat jadwal', 'Belum lunas setelah jadwal pembayarannya lewat.', 'termin', 'finance.termins.index',
            route('finance.termins.index', ['status' => TerminStatus::Overdue->value]),
            Termin::query()->byStatus(TerminStatus::Overdue->value)->with('project:id,name')->orderBy('scheduled_date'),
            fn (Termin $termin) => [
                'id' => $termin->id,
                'title' => $termin->project?->name ?? 'Proyek',
                'subtitle' => "Termin {$termin->termin_number} · jadwal ".$termin->scheduled_date?->translatedFormat('d M Y'),
                'at' => null,
                'href' => route('finance.termins.index', ['status' => TerminStatus::Overdue->value]),
            ],
        );
    }

    // ── Kepala Desain / QA ───────────────────────────────────────────────

    private function designsToAssign(User $user): ?array
    {
        return $this->group(
            'design-assign', 'Desain menunggu penugasan', 'Jasa Desain sudah dibayar — tugaskan arsitek.', 'design', 'design.index',
            route('design.index', ['status' => DesignStatus::MenungguPenugasan->value]),
            Design::query()->visibleTo($user)->byStatus(DesignStatus::MenungguPenugasan->value)->with('lead:id,client_name')->oldest('updated_at'),
            fn (Design $design) => [
                'id' => $design->id,
                'title' => $design->lead?->client_name ?? "Desain #{$design->id}",
                'subtitle' => $design->jenis_project,
                'at' => $design->updated_at,
                'href' => route('design.show', $design),
            ],
        );
    }

    /**
     * Designs where this architect is PIC or on the team (Design::visibleTo's
     * own-design rule, applied even to a Kepala Desain — this is *their* work,
     * not the division's), oldest first.
     *
     * @param  list<DesignStatus>  $statuses
     */
    private function ownDesigns(User $user, array $statuses, string $key, string $label, string $description): ?array
    {
        return $this->group(
            $key, $label, $description, 'design', 'design.index',
            route('design.index'),
            Design::query()
                ->where(fn (Builder $q) => $q
                    ->where('pic_id', $user->id)
                    ->orWhereHas('staff', fn (Builder $staff) => $staff->whereKey($user->id)))
                ->whereIn('status', array_map(fn (DesignStatus $status) => $status->value, $statuses))
                ->with('lead:id,client_name')
                ->oldest('updated_at'),
            fn (Design $design) => [
                'id' => $design->id,
                'title' => $design->lead?->client_name ?? "Desain #{$design->id}",
                'subtitle' => $design->jenis_project,
                'at' => $design->updated_at,
                'href' => route('design.show', $design),
            ],
        );
    }

    /** Sprint 24 D14 — the Pengawas (QA/QC) of the project (every QA on a project without one). */
    private function pendingQa(User $user): ?array
    {
        return $this->group(
            'qa-pending', 'Timeline menunggu pemeriksaan', 'Periksa timeline proyek yang Anda awasi.', 'qa', 'qa-forms.index',
            route('qa-forms.index', ['status' => QaStatus::Pending->value]),
            QaForm::query()
                ->where('status', QaStatus::Pending->value)
                ->whereHas('project', fn (Builder $project) => $project->qaReviewableBy($user))
                ->with(['project:id,name', 'milestone:id,name'])
                ->oldest('created_at')
                ->oldest('id'),
            fn (QaForm $form) => [
                'id' => $form->id,
                'title' => $form->project?->name ?? 'Proyek',
                'subtitle' => $form->milestone?->name,
                'at' => $form->created_at,
                'href' => route('qa-forms.show', $form),
            ],
        );
    }

    // ── Sprint 24: alur eksekusi proyek ──────────────────────────────────

    /** Kepala Desain — a new project's Gambar Kerja waiting for its architect. */
    private function workingDrawingsToAssign(): ?array
    {
        return $this->group(
            'working-drawing-assign', 'Gambar kerja menunggu penugasan', 'Proyek baru dibuka — tugaskan arsitek gambar kerjanya.', 'design', 'design.working-drawings.index',
            route('design.working-drawings.index', ['status' => WorkingDrawingStatus::MenungguPenugasan->value]),
            WorkingDrawing::query()->where('status', WorkingDrawingStatus::MenungguPenugasan->value)->with('project:id,name')->oldest('id'),
            fn (WorkingDrawing $drawing) => [
                'id' => $drawing->id,
                'title' => $drawing->project?->name ?? "Gambar Kerja #{$drawing->id}",
                'subtitle' => null,
                'at' => $drawing->created_at,
                'href' => route('design.working-drawings.show', $drawing),
            ],
        );
    }

    /** The architect's own Gambar Kerja in progress. */
    private function ownWorkingDrawings(User $user): ?array
    {
        return $this->group(
            'working-drawing-work', 'Gambar kerja sedang Anda kerjakan', 'Simpan link gambar kerja lalu tekan "Gambar Kerja Selesai".', 'design', 'design.working-drawings.index',
            route('design.working-drawings.index', ['status' => WorkingDrawingStatus::Dikerjakan->value]),
            WorkingDrawing::query()->ownedBy($user)->where('status', WorkingDrawingStatus::Dikerjakan->value)->with('project:id,name')->oldest('deadline'),
            fn (WorkingDrawing $drawing) => [
                'id' => $drawing->id,
                'title' => $drawing->project?->name ?? "Gambar Kerja #{$drawing->id}",
                'subtitle' => $drawing->deadline ? 'Deadline '.$drawing->deadline->translatedFormat('d M Y') : null,
                'at' => $drawing->assigned_at,
                'href' => route('design.working-drawings.show', $drawing),
            ],
        );
    }

    /**
     * The PM's own projects waiting on them in the execution flow: the TM
     * to run, a Pengawas payment or weekly report to decide, the 70 %
     * "Berangkat ke Lokasi".
     */
    private function executionForPm(User $user): ?array
    {
        $threshold = ProductionService::departureThreshold();

        return $this->group(
            'execution-pm', 'Proyek menunggu keputusan PM', 'Technical Meeting, pengajuan jasa pengawas, laporan mingguan, atau berangkat ke lokasi.', 'project', 'projects.index',
            route('projects.index'),
            Project::query()
                ->active()
                ->where('pm_id', $user->id)
                ->where(fn (Builder $q) => $q
                    ->where('phase', ProjectPhase::Tm->value)
                    ->orWhereHas('supervisorPayments', fn (Builder $payment) => $payment->where('status', SupervisorPaymentStatus::Diajukan->value))
                    ->orWhereHas('weeklyReports', fn (Builder $report) => $report->where('status', WeeklyReportStatus::Diajukan->value))
                    ->orWhere(fn (Builder $ready) => $ready->where('phase', ProjectPhase::Produksi->value)->whereNotNull('progress_70_notified_at')))
                ->oldest('phase_changed_at'),
            fn (Project $project) => [
                'id' => $project->id,
                'title' => $project->name,
                'subtitle' => match (true) {
                    $project->phase === ProjectPhase::Tm => 'Technical Meeting',
                    $project->phase === ProjectPhase::Produksi && $project->progress_70_notified_at !== null => "Actual activity ≥ {$threshold}% — berangkat ke lokasi?",
                    default => 'Pengajuan / laporan pengawas',
                },
                'at' => $project->phase_changed_at,
                'href' => route('projects.show', ['project' => $project, 'tab' => 'execution']),
            ],
        );
    }

    /**
     * The Pengawas at the Technical Meeting: when it is (the PM sets the
     * date) and how far the "bahan yang digunakan" plan is — straight to
     * the planner in the TM card.
     */
    private function meetingsForSupervisor(User $user): ?array
    {
        return $this->group(
            'tm-supervisor', 'Technical Meeting · giliran Pengawas', 'Lihat jadwal TM dan rencanakan bahan yang digunakan.', 'project', 'projects.index',
            route('projects.index'),
            Project::query()
                ->active()
                ->where('supervisor_id', $user->id)
                ->where('phase', ProjectPhase::Tm->value)
                ->with('technicalMeeting:id,project_id,meeting_date')
                ->withCount(['projectMaterials as planned_materials_count' => fn (Builder $line) => $line
                    ->where(fn (Builder $q) => $q->whereNull('request_status')->orWhere('request_status', MaterialRequestStatus::Disetujui->value))])
                ->oldest('phase_changed_at'),
            fn (Project $project) => [
                'id' => $project->id,
                'title' => $project->name,
                'subtitle' => collect([
                    ($date = $project->technicalMeeting?->meeting_date)
                        ? 'TM '.$date->locale('id')->translatedFormat('D, j M Y')
                        : 'Jadwal TM belum ditentukan PM',
                    $project->planned_materials_count > 0 ? "{$project->planned_materials_count} bahan direncanakan" : 'Bahan belum direncanakan',
                ])->implode(' · '),
                'at' => $project->phase_changed_at,
                'href' => route('projects.show', ['project' => $project, 'tab' => 'execution']).'#tm-bahan',
            ],
        );
    }

    /** The Pengawas's own steps: the DP conditions at PERSIAPAN, a rejected weekly report. */
    private function executionForSupervisor(User $user): ?array
    {
        return $this->group(
            'execution-supervisor', 'Proyek menunggu Pengawas', 'Lengkapi bahan, upah & daily target lalu ajukan DP, atau perbaiki laporan mingguan.', 'project', 'projects.index',
            route('projects.index'),
            Project::query()
                ->active()
                ->where('supervisor_id', $user->id)
                ->where(fn (Builder $q) => $q
                    ->where(fn (Builder $prep) => $prep
                        ->where('phase', ProjectPhase::Persiapan->value)
                        ->whereDoesntHave('supervisorPayments', fn (Builder $payment) => $payment
                            ->where('kind', SupervisorPaymentKind::Dp->value)
                            ->whereIn('status', SupervisorPaymentStatus::committedValues())))
                    ->orWhereHas('weeklyReports', fn (Builder $report) => $report->where('status', WeeklyReportStatus::Ditolak->value)))
                ->oldest('phase_changed_at'),
            fn (Project $project) => [
                'id' => $project->id,
                'title' => $project->name,
                'subtitle' => $project->phase === ProjectPhase::Persiapan ? 'Syarat produksi & DP pengawas' : 'Laporan mingguan ditolak PM',
                'at' => $project->phase_changed_at,
                'href' => route('projects.show', ['project' => $project, 'tab' => 'execution']),
            ],
        );
    }

    /** Finance — Jasa Pengawas the PM approved ("Bayar Pengawas: DP · Proyek X"). */
    private function supervisorPaymentsToPay(): ?array
    {
        return $this->group(
            'supervisor-pay', 'Jasa pengawas menunggu dibayar', 'Disetujui PM — catat pembayarannya.', 'salary', 'finance.supervisor-payments.index',
            route('finance.supervisor-payments.index'),
            SupervisorPayment::query()
                ->where('status', SupervisorPaymentStatus::DisetujuiPm->value)
                ->with(['project:id,name', 'weeklyReport:id,week_number'])
                ->oldest('decided_at'),
            fn (SupervisorPayment $payment) => [
                'id' => $payment->id,
                'title' => "Bayar Pengawas: {$payment->label}",
                'subtitle' => $this->rupiah($payment->amount),
                'at' => $payment->decided_at,
                'href' => route('finance.supervisor-payments.index'),
            ],
        );
    }

    // ── SDM ──────────────────────────────────────────────────────────────

    /** A review the CEO sent back (PerformanceReviewService::return() — DRAFT + return_note). */
    private function returnedReviews(): ?array
    {
        return $this->group(
            'review-returned', 'Evaluasi dikembalikan CEO', 'Perbaiki sesuai catatan CEO lalu ajukan lagi.', 'review', 'hr.reviews.index',
            route('hr.reviews.index', ['status' => ReviewStatus::Draft->value]),
            PerformanceReview::query()
                ->where('status', ReviewStatus::Draft->value)
                ->whereNotNull('return_note')
                ->with('employee:id,name')
                ->oldest('updated_at'),
            fn (PerformanceReview $review) => [
                'id' => $review->id,
                'title' => $review->employee?->name ?? 'Karyawan',
                'subtitle' => $review->periodLabel(),
                'at' => $review->updated_at,
                'href' => route('hr.reviews.show', $review),
            ],
        );
    }

    /** Manual KPI indicators of an OPEN month still without a value — they block closing it (KpiService::close()). */
    private function missingKpiScores(): ?array
    {
        return $this->group(
            'kpi-manual', 'Nilai KPI manual belum diisi', 'Isi indikator manual sebelum periode KPI ditutup.', 'kpi', 'hr.kpi.index',
            route('hr.kpi.index'),
            KpiScore::query()
                ->where('source', KpiIndicatorSource::Manual->value)
                ->whereNull('actual')
                ->whereHas('period', fn (Builder $period) => $period->where('status', KpiPeriodStatus::Open->value))
                ->with(['employee:id,name', 'period:id,period'])
                ->orderBy('kpi_period_id')
                ->orderBy('employee_id'),
            fn (KpiScore $score) => [
                'id' => $score->id,
                'title' => $score->employee?->name ?? 'Karyawan',
                'subtitle' => "{$score->indicator_name} · {$score->period?->period}",
                'at' => null,
                'href' => route('hr.kpi.index'),
            ],
        );
    }

    // ── Tukang ───────────────────────────────────────────────────────────

    /** TaskController@index for a Field Staff with ?due=today, not yet DONE. */
    private function tasksToday(User $user): ?array
    {
        return $this->group(
            'task-today', 'Tugas hari ini', 'Tugas dengan tenggat hari ini yang belum selesai.', 'task', 'tasks.index',
            route('tasks.index', ['due' => 'today']),
            Task::query()
                ->where('assignee_id', $user->id)
                ->byDue('today')
                ->where('status', '!=', TaskStatus::Done->value)
                ->with('project:id,name')
                ->orderBy('id'),
            fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'subtitle' => $task->project?->name,
                'at' => null,
                'href' => route('tasks.index', ['due' => 'today']),
            ],
        );
    }

    /**
     * The Form Harian fill-in list (Task::awaitingDailyForm()), shown on
     * the Tukang's Hari Ini screen. Only on working days — no penalty runs
     * on Sunday (H11, DailyFormSchedule).
     */
    private function dailyFormsMissing(User $user): ?array
    {
        $today = DailyFormSchedule::now();

        if (! DailyFormSchedule::isWorkDay($today)) {
            return null;
        }

        return $this->group(
            'daily-form', 'Target hari ini belum dilaporkan', 'Centang yang selesai atau isi kendala sebelum '.DailyFormSchedule::penaltyAt().' WIB supaya tidak kena penalti.', 'dailyform', 'today.index',
            route('today.index'),
            Task::query()->awaitingDailyForm($user, $today->toDateString())->with('project:id,name')->orderBy('id'),
            fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'subtitle' => $task->project?->name,
                'at' => null,
                'href' => route('today.index'),
            ],
        );
    }
}
