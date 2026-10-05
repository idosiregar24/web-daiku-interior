<?php

namespace Database\Seeders\Demo;

use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\QuotationStatus;
use App\Enums\QuotationType;
use App\Models\BankAccount;
use App\Models\BudgetLine;
use App\Models\Design;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Project;
use App\Models\ProjectOpening;
use App\Models\QaForm;
use App\Models\Quotation;
use App\Models\Termin;
use App\Models\Unit;
use App\Models\User;
use App\Services\BudgetRealizationService;
use App\Services\DesignService;
use App\Services\InvoiceService;
use App\Services\LeadService;
use App\Services\MaterialRequestService;
use App\Services\MilestoneService;
use App\Services\ProjectBudgetService;
use App\Services\ProjectService;
use App\Services\QaFormService;
use App\Services\QuotationService;
use App\Services\TaskService;
use App\Services\TerminService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Demo coverage of every branch of the business flow (Sprint 12 revisi
 * alur), on top of DemoDataSeeder: one lead or project per state that the
 * main demo doesn't already show — so every page, filter, chip, queue and
 * notice has something to display. Everything goes through the real
 * services (same rules, audit and notifications as the app).
 *
 * Covered here: surveys DIJADWALKAN / SIAP / SELESAI / BATAL; RAB at
 * DIMINTA, APPROVED_INTERNAL, READY_TO_SEND, SENT_TO_CLIENT (valid and
 * expired), returned by the CEO, rejected by the client, CANCELLED; Jasa
 * Desain invoices waiting for verification and rejected by Finance;
 * designs in REVISI_DESAIN and ACC_DESAIN; an approved RAB Proyek waiting
 * for "Buka Proyek"; projects ON_HOLD, CANCELLED and COMPLETED; a running
 * project with an OVERDUE milestone, a milestone waiting for QA, tasks in
 * PENGECEKAN and OVER, an OVERDUE termin, an INVOICED termin, a rejected
 * material request, a corrected realisation, an approved and a rejected
 * overrun, and a RAB Tambahan waiting for the PM.
 */
class WorkflowScenarioSeeder extends Seeder
{
    private User $ceo;

    private User $marketing;

    private User $estimator;

    private User $pm;

    private User $finance;

    private User $qa;

    private User $designer;

    private User $head;

    private User $tukang;

    private int $contact = 0;

    private LeadService $leads;

    private QuotationService $quotations;

    private InvoiceService $invoices;

    public function run(): void
    {
        $this->ceo = $this->user('CEO');
        $this->marketing = $this->user('MARKETING');
        $this->estimator = $this->user('ESTIMATOR');
        $this->pm = $this->user('PM');
        $this->finance = $this->user('FINANCE');
        $this->qa = $this->user('QA');
        $this->designer = $this->user('DESIGNER');
        $this->head = $this->user('KEPALA_DESAIN');
        $this->tukang = $this->user('FIELD_STAFF');
        $this->leads = app(LeadService::class);
        $this->quotations = app(QuotationService::class);
        $this->invoices = app(InvoiceService::class);

        $this->surveys();
        $this->quotationStates();
        $this->designInvoices();
        $this->designStates();
        $this->waitingProjectOpening();
        $this->closedProjects();
        $this->busyProject();
    }

    // ── CRM: survey branches ────────────────────────────────────────────

    private function surveys(): void
    {
        // A plain new lead, first follow-up still ahead.
        $this->lead('Lina Marlina', ['follow_up_date' => now()->addDays(2)->toDateString(), 'priority' => 'HOT']);

        // Pekanbaru survey scheduled next week.
        $this->leads->submitRequest($this->lead('Dodi Pratama'), [
            'type' => 'SURVEY',
            'scheduled_at' => now()->addWeek()->setTime(9, 0)->toDateTimeString(),
            'is_outside_pekanbaru' => false,
            'note' => 'Ukur ruang tamu & dapur.',
        ], $this->marketing);

        // Survey done, result written.
        $yuni = $this->lead('Yuni Astuti');
        $this->leads->submitRequest($yuni, [
            'type' => 'SURVEY',
            'scheduled_at' => now()->subDays(3)->setTime(10, 0)->toDateTimeString(),
            'is_outside_pekanbaru' => false,
        ], $this->marketing);
        $this->leads->completeSurvey($yuni->surveys()->sole(), ['result_note' => 'Ruang 4×6 m, plafon 3 m, klien ingin kitchen set L + minibar.']);

        // Survey cancelled by the client.
        $agus = $this->lead('Agus Salim');
        $this->leads->submitRequest($agus, [
            'type' => 'SURVEY',
            'scheduled_at' => now()->addDays(4)->setTime(13, 0)->toDateTimeString(),
            'is_outside_pekanbaru' => false,
        ], $this->marketing);
        $this->leads->cancelSurvey($agus->surveys()->sole(), 'Klien sedang ke luar kota, minta dijadwalkan ulang bulan depan.', $this->marketing);

        // Outside Pekanbaru: RAB Jasa Survey approved and paid → survey SIAP.
        $rahmat = $this->lead('Rahmat Hidayat', ['city' => 'Dumai', 'address' => 'Jl. Sultan Syarif Kasim, Dumai']);
        $this->leads->submitRequest($rahmat, [
            'type' => 'SURVEY',
            'scheduled_at' => now()->addDays(5)->setTime(9, 0)->toDateTimeString(),
            'is_outside_pekanbaru' => true,
        ], $this->marketing);
        $this->leads->submitRequest($rahmat->fresh(), ['type' => 'RAB_SURVEY', 'note' => 'Survey ke Dumai (±190 km) — transport + akomodasi 1 malam.'], $this->marketing);
        $survey = $this->rab($rahmat, QuotationType::Survey, 'approved');
        $this->pay($this->invoices->issueForQuotation($survey, ['due_date' => now()->addDays(2)->toDateString()], $this->marketing));
    }

    // ── Quotation: every stop of the RAB flow ───────────────────────────

    private function quotationStates(): void
    {
        $this->rab($this->lead('Mega Lestari'), QuotationType::Desain, 'requested');
        $this->rab($this->lead('Wulan Sari'), QuotationType::Proyek, 'approved_internal');
        $this->rab($this->lead('Bayu Saputra'), QuotationType::Proyek, 'ready');
        $this->rab($this->lead('Nina Kurnia'), QuotationType::Proyek, 'sent');

        // Sent 20 days ago — its 14-day validity is over; the client's link says so.
        Carbon::setTestNow(now()->subDays(20));
        $this->rab($this->lead('Teguh Wibowo'), QuotationType::Proyek, 'sent');
        Carbon::setTestNow();

        // The client asked for changes → back to DRAFT as version 2.
        $putri = $this->rab($this->lead('Putri Ayu'), QuotationType::Proyek, 'sent');
        $this->quotations->clientReject($putri, $this->marketing, 'Klien minta HPL diganti duco dan budget turun ±10%.');

        // The CEO sent it back → DRAFT version 2.
        $hadi = $this->rab($this->lead('Hadi Susanto'), QuotationType::Proyek, 'waiting_ceo');
        $this->quotations->review($hadi, ['decision' => 'return', 'note' => 'Margin terlalu tipis — cek ulang harga multiplek.', 'items' => []], $this->ceo);

        // Cancelled by Marketing.
        $ratna = $this->rab($this->lead('Ratna Dewi'), QuotationType::Proyek, 'submitted');
        $this->quotations->cancel($ratna, $this->marketing, 'Klien memutuskan tidak jadi renovasi tahun ini.');

        // RAB Jasa Desain with the client, not answered yet.
        $this->rab($this->lead('Indra Lesmana'), QuotationType::Desain, 'sent');
    }

    // ── Jasa Desain invoices: proof waiting, proof rejected ─────────────

    private function designInvoices(): void
    {
        $siska = $this->rab($this->lead('Siska Amelia'), QuotationType::Desain, 'approved');
        $invoice = $this->invoices->issueForQuotation($siska, ['due_date' => now()->addDays(3)->toDateString()], $this->marketing);
        $this->invoices->submitProof($invoice, 'https://drive.google.com/demo-bukti-siska', $this->marketing);

        $robert = $this->rab($this->lead('Robert Tan'), QuotationType::Desain, 'approved');
        $invoice = $this->invoices->issueForQuotation($robert, ['due_date' => now()->addDays(3)->toDateString()], $this->marketing);
        $this->invoices->submitProof($invoice, 'https://drive.google.com/demo-bukti-robert', $this->marketing);
        $this->invoices->reject($invoice, 'Nominal transfer Rp 500.000 kurang dari tagihan — minta klien transfer kekurangannya.', $this->finance);
    }

    // ── Design: revision asked, approved by the client ──────────────────

    private function designStates(): void
    {
        $designs = app(DesignService::class);

        foreach (['Andi Wijaya' => 'revision', 'Sari Indah' => 'approved'] as $name => $stage) {
            $lead = $this->lead($name);
            $quotation = $this->rab($lead, QuotationType::Desain, 'approved');
            $this->pay($this->invoices->issueForQuotation($quotation, ['due_date' => now()->toDateString()], $this->marketing));

            $design = $designs->assign(Design::where('lead_id', $lead->id)->sole(), [
                'pic_id' => $this->designer->id,
                'start_date' => now()->subDays(10)->toDateString(),
                'target_hari' => 14,
            ], $this->head);
            $designs->update($design, [
                'jenis_project' => 'RUANG_TAMU_TV',
                'design_urls' => ['https://drive.google.com/demo-desain-'.str($name)->slug()],
            ]);
            $designs->sendToClient($design, $this->marketing);

            if ($stage === 'revision') {
                $designs->requestRevision($design, 'Klien minta backdrop TV diganti panel kayu, bukan marmer.', $this->marketing);
            } else {
                // → ACC_DESAIN and a RAB Proyek asked from the Estimator (DIMINTA).
                $designs->markClientApproved($design, $this->marketing);
            }
        }
    }

    // ── Approved RAB Proyek waiting for the CEO's "Buka Proyek" ─────────

    private function waitingProjectOpening(): void
    {
        $this->rab($this->lead('Kevin Halim'), QuotationType::Proyek, 'approved', [
            ['label' => 'DP', 'percentage' => 40, 'trigger' => 'DI_MUKA'],
            ['label' => 'Pelunasan', 'percentage' => 60, 'trigger' => 'PROYEK_SELESAI'],
        ]);
    }

    // ── Projects ON_HOLD, CANCELLED, COMPLETED ──────────────────────────

    private function closedProjects(): void
    {
        $projects = app(ProjectService::class);

        $onHold = $this->openedProject('Joko Purnomo');
        $projects->update($onHold, $this->editData($onHold, ProjectStatus::OnHold, 'Klien menunda pekerjaan sampai renovasi atap selesai.'), $this->pm);

        // Cancelled before it started (its DP never fell due).
        $cancelled = $this->openedProject('Linda Kusuma', startedDaysAgo: -7);
        $projects->update($cancelled, $this->editData($cancelled, ProjectStatus::Cancelled, 'Klien membatalkan — rumah dijual sebelum pekerjaan dimulai.'), $this->ceo);

        // Completed: DP paid, the only milestone passed QA, pelunasan invoiced.
        $done = $this->openedProject('Hartono Wijaya', [
            ['label' => 'DP', 'percentage' => 50, 'trigger' => 'DI_MUKA'],
            ['label' => 'Pelunasan', 'percentage' => 50, 'trigger' => 'PROYEK_SELESAI'],
        ], startedDaysAgo: 40);
        $termins = $done->termins()->get();
        $this->pay($this->invoices->issueForTermin($termins[0], ['due_date' => now()->subDays(38)->toDateString()], $this->marketing));

        $milestones = app(MilestoneService::class);
        $milestone = $milestones->create($done, ['name' => 'Pemasangan', 'target_date' => now()->subDays(3)->toDateString()]);
        $milestones->update($milestone, ['name' => 'Pemasangan', 'target_date' => $milestone->target_date, 'status' => MilestoneStatus::InProgress->value]);
        $milestones->markDone($milestone);
        app(QaFormService::class)->review(QaForm::where('milestone_id', $milestone->id)->sole(), 'approve', [
            ['label' => 'Hasil pekerjaan sesuai spesifikasi/desain', 'passed' => true, 'note' => null],
        ], 'Serah terima selesai.', $this->qa);

        $this->invoices->issueForTermin($termins[1]->fresh(), ['due_date' => now()->addDays(7)->toDateString()], $this->marketing);
    }

    // ── A busy running project: every operational branch at once ────────

    private function busyProject(): void
    {
        $project = $this->openedProject('Maya Anggraini', [
            ['label' => 'DP', 'percentage' => 30, 'trigger' => 'DI_MUKA'],
            ['label' => 'Termin 2', 'percentage' => 30, 'trigger' => 'TANGGAL', 'due_date' => now()->subDays(5)->toDateString()],
            ['label' => 'Pelunasan', 'percentage' => 40, 'trigger' => 'PROYEK_SELESAI'],
        ], startedDaysAgo: 30);
        $termins = $project->termins()->get();
        $this->pay($this->invoices->issueForTermin($termins[0], ['due_date' => now()->subDays(28)->toDateString()], $this->marketing));

        // Milestones: one waiting for QA, one past its target (→ OVERDUE).
        $milestones = app(MilestoneService::class);
        $late = $milestones->create($project, ['name' => 'Persiapan & Bongkar', 'target_date' => now()->subDays(8)->toDateString()]);
        $production = $milestones->create($project, ['name' => 'Produksi Furnitur', 'target_date' => now()->addDays(10)->toDateString()]);
        $milestones->update($production, ['name' => $production->name, 'target_date' => $production->target_date, 'status' => MilestoneStatus::InProgress->value]);
        $milestones->markDone($production);

        // Tasks: one being checked, one past due (→ OVER).
        $tasks = app(TaskService::class);
        $checking = $tasks->create($project, [
            'milestone_id' => $late->id, 'title' => 'Bongkar plafon lama', 'assignee_id' => $this->tukang->id,
            'due_date' => now()->addDay()->toDateString(), 'priority' => 'HIGH', 'rate_per_task' => 200_000,
        ], $this->pm);
        $tasks->updateStatus($checking, ['status' => 'PENGECEKAN'], $this->tukang);
        $tasks->create($project, [
            'milestone_id' => $late->id, 'title' => 'Pasang rangka hollow', 'assignee_id' => $this->tukang->id,
            'due_date' => now()->subDays(2)->toDateString(), 'priority' => 'MEDIUM', 'rate_per_task' => 150_000,
        ], $this->pm);

        // The daily jobs: overdue milestones / tasks / termins.
        $milestones->markOverdueMilestones();
        $tasks->markOverdueTasks();
        app(TerminService::class)->markOverdue();

        // A Tukang's request the PM turned down.
        $materials = app(MaterialRequestService::class);
        $line = $materials->submit($project, ['name' => 'Engsel sendok soft-close', 'qty' => 12, 'reason' => 'Engsel lama berkarat'], $this->tukang);
        $materials->pmDecide($line, 'reject', 'Masih ada stok engsel di gudang proyek — pakai itu dulu.', $this->pm);

        // Allocation: a corrected realisation, an approved and a rejected overrun.
        $budget = app(ProjectBudgetService::class);
        $realizations = app(BudgetRealizationService::class);
        $items = $budget->sourceItems($project)->keyBy('description');
        $interior = $budget->createPost($project, 'Interior', $this->pm);
        $electrical = $budget->createPost($project, 'Listrik', $this->pm);
        $budget->allocate($interior, [$items['Kitchen Set Custom']->id, $items['Partisi Ruangan']->id], $this->pm);
        $budget->allocate($electrical, [$items['Instalasi Listrik & Lampu']->id], $this->pm);

        $line = fn (string $description) => BudgetLine::where('description', $description)->whereIn('budget_post_id', [$interior->id, $electrical->id])->sole();
        $wrong = $realizations->record($line('Kitchen Set Custom'), ['qty_actual' => 1, 'unit_cost' => 1_800_000, 'note' => 'Salah ketik — kurang satu nol.'], $this->pm);
        $realizations->reverse($wrong, 'Salah ketik nominal', $this->pm);
        $realizations->record($line('Kitchen Set Custom'), ['qty_actual' => 1, 'unit_cost' => 18_000_000, 'note' => 'Multiplek + HPL.'], $this->pm);

        $approved = $realizations->requestOverrun($line('Instalasi Listrik & Lampu'), [
            'qty_actual' => 1, 'unit_cost' => 6_500_000, 'note' => 'Tambah 6 titik downlight.', 'reason' => 'Klien minta tambahan titik lampu saat pemasangan.',
        ], $this->pm);
        $realizations->decide($approved, true, 'Disetujui — tagihkan lewat RAB Tambahan.', $this->ceo);

        $rejected = $realizations->requestOverrun($line('Instalasi Listrik & Lampu'), [
            'qty_actual' => 1, 'unit_cost' => 2_000_000, 'note' => 'Kabel cadangan.', 'reason' => 'Stok kabel habis.',
        ], $this->pm);
        $realizations->decide($rejected, false, 'Pakai sisa kabel proyek Budi dulu.', $this->ceo);

        // A RAB Tambahan built by the Estimator, waiting for the PM.
        $addendum = $this->quotations->requestAddendum($project->fresh(), 'Tambah rak dinding & lampu gantung di ruang makan.', $this->marketing);
        $this->quotations->startDraft($addendum, $this->estimator);
        $this->quotations->replaceItems($addendum, [
            ['description' => 'Rak Dinding Ambalan', 'qty' => 3, 'unit_id' => $this->unit('unit'), 'unit_price' => 450_000],
            ['description' => 'Lampu Gantung', 'qty' => 2, 'unit_id' => $this->unit('unit'), 'unit_price' => 750_000],
        ]);
        $this->quotations->submit($addendum);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function user(string $role): User
    {
        return User::role($role)->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    private function lead(string $name, array $extra = []): Lead
    {
        $this->contact++;

        return $this->leads->create([
            'client_name' => $name,
            'contact' => '0812-7777-'.str_pad((string) $this->contact, 4, '0', STR_PAD_LEFT),
            'source' => 'Instagram',
            'priority' => 'WARM',
            'category' => 'RESIDENTIAL',
            'city' => 'Pekanbaru',
            'assigned_to' => $this->marketing->id,
            ...$extra,
        ], $this->marketing);
    }

    private function unit(string $code): int
    {
        return Unit::firstOrCreate(['code' => $code], ['name' => ucfirst($code)])->id;
    }

    /**
     * Asks for a RAB of `$type` on the lead and walks it to `$stop`:
     * requested · draft · submitted · waiting_ceo · approved_internal ·
     * ready · sent · approved (the client approved on the link).
     *
     * @param  list<array<string, mixed>>|null  $terms
     */
    private function rab(Lead $lead, QuotationType $type, string $stop, ?array $terms = null): Quotation
    {
        $quotation = $lead->quotations()->where('type', $type->value)->whereNotIn('status', QuotationStatus::closedValues())->first();

        if (! $quotation) {
            $this->leads->submitRequest($lead->fresh(), ['type' => 'RAB_'.$type->value, 'note' => "Mohon disusun {$type->label()}."], $this->marketing);
            $quotation = $lead->quotations()->where('type', $type->value)->latest('id')->firstOrFail();
        }

        $steps = ['requested', 'draft', 'submitted', 'waiting_ceo', 'approved_internal', 'ready', 'sent', 'approved'];
        $reached = fn (string $step) => array_search($stop, $steps, true) >= array_search($step, $steps, true);

        if (! $reached('draft')) {
            return $quotation;
        }

        $this->quotations->startDraft($quotation, $this->estimator);
        $this->quotations->replaceItems($quotation, $this->itemsFor($type));

        if ($terms) {
            $this->quotations->savePaymentTerms($quotation, $terms);
        }

        if (! $reached('submitted')) {
            return $quotation->fresh();
        }

        $this->quotations->submit($quotation->fresh());

        if (! $reached('waiting_ceo')) {
            return $quotation->fresh();
        }

        $this->approveAll($quotation->fresh(), $this->pm);

        if ($type === QuotationType::Proyek) {
            if ($stop === 'waiting_ceo') {
                return $quotation->fresh();
            }

            $this->approveAll($quotation->fresh(), $this->ceo);
        }

        if (! $reached('ready')) {
            return $quotation->fresh();
        }

        $this->quotations->sendToMarketing($quotation->fresh(), $this->estimator);

        if (! $reached('sent')) {
            return $quotation->fresh();
        }

        $this->quotations->sendToClient($quotation->fresh(), $this->marketing);

        if (! $reached('approved')) {
            return $quotation->fresh();
        }

        $this->quotations->clientApprove($quotation->fresh()->currentShareLink(), true, '127.0.0.1', 'WorkflowScenarioSeeder');

        return $quotation->fresh();
    }

    private function approveAll(Quotation $quotation, User $reviewer): void
    {
        $this->quotations->review($quotation, [
            'decision' => 'approve',
            'items' => $quotation->items()->get()->map(fn ($item) => ['item_id' => $item->id, 'verdict' => 'OK'])->all(),
        ], $reviewer);
    }

    /** @return list<array<string, mixed>> */
    private function itemsFor(QuotationType $type): array
    {
        return match ($type) {
            QuotationType::Survey => [
                ['description' => 'Transport & Akomodasi Tim Survey', 'qty' => 1, 'unit_id' => $this->unit('ls'), 'unit_price' => 1_500_000],
            ],
            QuotationType::Desain => [
                ['description' => 'Jasa Desain Interior + Render 3D', 'qty' => 1, 'unit_id' => $this->unit('ls'), 'unit_price' => 4_500_000],
            ],
            QuotationType::Proyek => [
                ['description' => 'Kitchen Set Custom', 'qty' => 1, 'unit_id' => $this->unit('set'), 'unit_price' => 22_000_000],
                ['description' => 'Partisi Ruangan', 'qty' => 2, 'unit_id' => $this->unit('unit'), 'unit_price' => 3_000_000],
                ['description' => 'Instalasi Listrik & Lampu', 'qty' => 1, 'unit_id' => $this->unit('ls'), 'unit_price' => 5_000_000],
            ],
        };
    }

    /** Invoice → proof → Finance verifies it. */
    private function pay(Invoice $invoice): void
    {
        $this->invoices->submitProof($invoice, 'https://drive.google.com/demo-bukti-'.$invoice->id, $this->marketing);
        $this->invoices->verify($invoice, ['bank_account_id' => BankAccount::firstOrFail()->id, 'paid_date' => now()->toDateString()], $this->finance);
    }

    /**
     * A project opened by the CEO from an approved RAB Proyek.
     *
     * @param  list<array<string, mixed>>|null  $terms
     */
    private function openedProject(string $client, ?array $terms = null, int $startedDaysAgo = 14): Project
    {
        $quotation = $this->rab($this->lead($client), QuotationType::Proyek, 'approved', $terms ?? [
            ['label' => 'DP', 'percentage' => 30, 'trigger' => 'DI_MUKA'],
            ['label' => 'Pelunasan', 'percentage' => 70, 'trigger' => 'PROYEK_SELESAI'],
        ]);

        return app(ProjectService::class)->openFromQuotation(ProjectOpening::where('quotation_id', $quotation->id)->sole(), [
            'name' => 'Proyek '.$client,
            'pm_id' => $this->pm->id,
            'start_date' => now()->subDays($startedDaysAgo)->toDateString(),
        ], $this->ceo);
    }

    /** @return array<string, mixed> */
    private function editData(Project $project, ProjectStatus $status, string $note): array
    {
        return [
            'name' => $project->name,
            'start_date' => $project->start_date->toDateString(),
            'contract_value' => $project->contract_value,
            'status' => $status->value,
            'note' => $note,
        ];
    }
}
