<?php

namespace App\Services;

use App\Enums\DesignStatus;
use App\Enums\InvoiceStatus;
use App\Enums\KpiIndicatorSource;
use App\Enums\KpiPeriodStatus;
use App\Enums\LeadStatus;
use App\Enums\MaterialRequestStatus;
use App\Enums\OvertimeStatus;
use App\Enums\QaStatus;
use App\Enums\QuotationStatus;
use App\Enums\ReviewStatus;
use App\Enums\TaskStatus;
use App\Enums\TerminStatus;
use App\Models\BudgetOverrunRequest;
use App\Models\Design;
use App\Models\Invoice;
use App\Models\KpiScore;
use App\Models\Lead;
use App\Models\OvertimeRequest;
use App\Models\PerformanceReview;
use App\Models\ProjectMaterial;
use App\Models\ProjectOpening;
use App\Models\QaForm;
use App\Models\Quotation;
use App\Models\SalaryChange;
use App\Models\Task;
use App\Models\Termin;
use App\Models\User;
use App\Support\DailyFormSchedule;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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
            $groups[] = $this->quotations($user, QuotationStatus::WaitingCeo, 'quotation-ceo', 'RAB menunggu keputusan CEO', 'Review item RAB Proyek yang sudah di-ACC PM.');
            $groups[] = $this->projectOpenings();
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
            // pmApprove is `role:PM` — the Asisten PM doesn't decide overtime.
            $groups[] = $this->overtime(OvertimeStatus::Pending, 'overtime-pm', 'Lembur menunggu ACC PM');
        }

        if ($user->hasRole('ESTIMATOR')) {
            $groups[] = $this->quotations($user, QuotationStatus::Diminta, 'quotation-requested', 'RAB diminta Marketing', 'Ambil dan susun RAB yang diminta.');
            $groups[] = $this->returnedQuotations();
        }

        if ($user->hasRole('MARKETING')) {
            $groups[] = $this->dueFollowUps($user);
            $groups[] = $this->quotations($user, QuotationStatus::ReadyToSend, 'quotation-send', 'RAB siap dikirim ke klien', 'Kirim link persetujuan ke klien.');
            $groups[] = $this->terminsToInvoice($user);
        }

        if ($user->hasRole('FINANCE')) {
            $groups[] = $this->invoicesToVerify();
            $groups[] = $this->overtime(OvertimeStatus::PendingFinance, 'overtime-finance', 'Lembur menunggu ACC Finance');
            $groups[] = $this->overdueTermins();
        }

        if ($user->hasRole('KEPALA_DESAIN')) {
            $groups[] = $this->designsToAssign($user);
        }

        if ($user->hasRole('LOGISTICS')) {
            $groups[] = $this->materialRequests($user, MaterialRequestStatus::Diajukan, 'material-logistics', 'Pengajuan barang menunggu Logistik', 'Cek katalog, setujui atau tolak.');
        }

        if ($user->hasRole('QA')) {
            $groups[] = $this->pendingQa();
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
                'subtitle' => "{$q->type?->label()} v{$q->version} · {$this->rupiah($q->total_amount)}",
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
                'subtitle' => "{$q->type?->label()} v{$q->version}",
                'at' => $q->updated_at,
                'href' => route('quotations.show', $q),
            ],
        );
    }

    // ── CEO ──────────────────────────────────────────────────────────────

    /** Sprint 12 #19 — the same rows as the CEO's "Buka Proyek" pop-up. */
    private function projectOpenings(): ?array
    {
        return $this->group(
            'project-opening', 'RAB Proyek siap dibuka jadi proyek', 'Klien sudah menyetujui — tentukan PM lalu Buka Proyek.', 'project', 'projects.index',
            route('projects.index'),
            ProjectOpening::query()->waiting()->with(['lead:id,client_name', 'quotation:id,total_amount,version'])->oldest(),
            fn (ProjectOpening $opening) => [
                'id' => $opening->id,
                'title' => $opening->lead?->client_name ?? "RAB #{$opening->quotation_id}",
                'subtitle' => 'RAB Proyek v'.$opening->quotation?->version.' · '.$this->rupiah($opening->quotation?->total_amount),
                'at' => $opening->created_at,
                'href' => route('quotations.show', $opening->quotation_id),
            ],
        );
    }

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
                'href' => route('projects.show', ['project' => $overrun->post?->project_id, 'tab' => 'budget']),
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
            'qa-rejected', 'QA ditolak — perlu perbaikan', 'Perbaiki temuan QA lalu ajukan ulang dari tab Milestone proyek.', 'qa', 'projects.index',
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
                'href' => route('projects.show', ['project' => $form->project_id, 'tab' => 'qa']),
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
                'href' => route('projects.show', ['project' => $termin->project_id, 'tab' => 'finance']),
            ],
        );
    }

    // ── Finance ──────────────────────────────────────────────────────────

    /** InvoiceController@verification — MENUNGGU_VERIFIKASI, oldest proof first. */
    private function invoicesToVerify(): ?array
    {
        return $this->group(
            'invoice-verify', 'Pembayaran menunggu verifikasi', 'Cocokkan bukti bayar dengan mutasi rekening.', 'invoice', 'finance.invoices.verification',
            route('finance.invoices.verification'),
            Invoice::query()->byStatus(InvoiceStatus::MenungguVerifikasi->value)->with('lead:id,client_name')->orderBy('proof_submitted_at'),
            fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'title' => $invoice->number,
                'subtitle' => trim(($invoice->lead?->client_name ?? '').' · '.$this->rupiah($invoice->amount), ' ·'),
                'at' => $invoice->proof_submitted_at,
                'href' => route('finance.invoices.verification'),
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

    private function pendingQa(): ?array
    {
        return $this->group(
            'qa-pending', 'Milestone menunggu QA', 'Periksa milestone yang selesai dikerjakan.', 'qa', 'qa-forms.index',
            route('qa-forms.index', ['status' => QaStatus::Pending->value]),
            QaForm::query()->where('status', QaStatus::Pending->value)->with(['project:id,name', 'milestone:id,name'])->oldest('created_at')->oldest('id'),
            fn (QaForm $form) => [
                'id' => $form->id,
                'title' => $form->project?->name ?? 'Proyek',
                'subtitle' => $form->milestone?->name,
                'at' => $form->created_at,
                'href' => route('qa-forms.show', $form),
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
            'daily-form', 'Form harian belum diisi', 'Isi sebelum '.DailyFormSchedule::penaltyAt().' WIB supaya tidak kena penalti.', 'dailyform', 'today.index',
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
