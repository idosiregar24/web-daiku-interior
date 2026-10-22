<?php

namespace Database\Seeders\Demo;

use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\QuotationStatus;
use App\Enums\QuotationType;
use App\Models\BankAccount;
use App\Models\BudgetLine;
use App\Models\City;
use App\Models\Design;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Milestone;
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
use App\Services\ProductionService;
use App\Services\ProjectBudgetService;
use App\Services\ProjectService;
use App\Services\QaFormService;
use App\Services\QuotationService;
use App\Services\SupervisorPaymentService;
use App\Services\TaskService;
use App\Services\TerminService;
use App\Services\WeeklyReportService;
use App\Services\WorkItemQaService;
use App\Services\WorkItemService;
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
 * DIMINTA, READY_TO_SEND (with a CEO note), SENT_TO_CLIENT (valid and
 * expired), returned by the PM, rejected by the client (recorded by
 * Marketing, and CLIENT_REJECTED on the link — Sprint 23), CANCELLED; Jasa
 * Desain invoices waiting for verification and rejected by Finance;
 * designs in DESAIN (WhatsApp group + links) and ACC_DESAIN; an approved RAB Proyek waiting
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
        $this->executionFlow();
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
        $rahmat = $this->lead('Rahmat Hidayat', ['city_id' => City::idFor('Dumai'), 'address' => 'Jl. Sultan Syarif Kasim, Dumai']);
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
        // Sprint 23 K11 — the client said "Tidak Setuju" on the link: Marketing's turn.
        $wulan = $this->rab($this->lead('Wulan Sari'), QuotationType::Proyek, 'sent');
        $this->quotations->clientDecline($wulan->currentShareLink(), 'Harga lemari terlalu tinggi, mohon opsi finishing HPL.', 'WorkflowScenarioSeeder');

        // Sprint 23 K9 — with Marketing, plus the CEO's information-only note.
        $bayu = $this->rab($this->lead('Bayu Saputra'), QuotationType::Proyek, 'ready');
        $this->quotations->addCeoNote($bayu, 'Pastikan klien tahu jadwal produksi ±6 minggu sebelum dikirim.', $this->ceo);
        $this->rab($this->lead('Nina Kurnia'), QuotationType::Proyek, 'sent');

        // Sent 20 days ago — its 14-day validity is over; the client's link says so.
        Carbon::setTestNow(now()->subDays(20));
        $this->rab($this->lead('Teguh Wibowo'), QuotationType::Proyek, 'sent');
        Carbon::setTestNow();

        // The client asked for changes → back to DRAFT as version 2.
        $putri = $this->rab($this->lead('Putri Ayu'), QuotationType::Proyek, 'sent');
        $this->quotations->clientReject($putri, $this->marketing, 'Klien minta HPL diganti duco dan budget turun ±10%.');

        // The PM sent it back → DRAFT version 2.
        $hadi = $this->rab($this->lead('Hadi Susanto'), QuotationType::Proyek, 'submitted');
        $this->quotations->review($hadi, [
            'decision' => 'return',
            'note' => 'Margin terlalu tipis — cek ulang harga multiplek.',
            'items' => $hadi->items()->get()->map(fn ($item) => ['item_id' => $item->id, 'verdict' => 'OK'])->all(),
        ], $this->pm);

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
        $this->invoices->submitProof($invoice, ['payment_proof_url' => 'https://drive.google.com/demo-bukti-siska'], $this->marketing);

        $robert = $this->rab($this->lead('Robert Tan'), QuotationType::Desain, 'approved');
        $invoice = $this->invoices->issueForQuotation($robert, ['due_date' => now()->addDays(3)->toDateString()], $this->marketing);
        $this->invoices->submitProof($invoice, ['payment_proof_url' => 'https://drive.google.com/demo-bukti-robert'], $this->marketing);
        $this->invoices->reject($invoice, 'Nominal transfer Rp 500.000 kurang dari tagihan — minta klien transfer kekurangannya.', $this->finance);
    }

    // ── Design: worked on in the WhatsApp group, approved by the client ─

    private function designStates(): void
    {
        $designs = app(DesignService::class);

        foreach (['Andi Wijaya' => 'designing', 'Sari Indah' => 'approved'] as $name => $stage) {
            $lead = $this->lead($name);
            $quotation = $this->rab($lead, QuotationType::Desain, 'approved');
            $this->pay($this->invoices->issueForQuotation($quotation, ['due_date' => now()->toDateString()], $this->marketing));

            $design = $designs->assign(Design::where('lead_id', $lead->id)->sole(), [
                'pic_id' => $this->designer->id,
                'start_date' => now()->subDays(10)->toDateString(),
                'target_hari' => 14,
            ], $this->head);
            // Sprint 23 K18 — revisions are asked in the WhatsApp group; the system keeps the links.
            $designs->updateLinks($design, [
                'whatsapp_group_url' => 'https://chat.whatsapp.com/Demo'.str($name)->studly(),
                'design_urls' => ['https://drive.google.com/demo-desain-'.str($name)->slug()],
            ], $this->designer);

            if ($stage === 'approved') {
                // → ACC_DESAIN and a RAB Proyek asked from the Estimator (DIMINTA).
                $designs->markClientApproved($design, $this->marketing);
            }
        }
    }

    // ── Approved RAB Proyek waiting for the PM's "Buka Proyek" ──────────

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
        $late = $milestones->create($project, ['name' => 'Bongkar & Rangka Plafon', 'start_date' => now()->subDays(20)->toDateString(), 'target_date' => now()->subDays(8)->toDateString()]);
        $production = $milestones->create($project, ['name' => 'Produksi Kitchen Set & Partisi', 'start_date' => now()->subDays(7)->toDateString(), 'target_date' => now()->addDays(10)->toDateString()]);
        $milestones->update($production, ['name' => $production->name, 'target_date' => $production->target_date, 'status' => MilestoneStatus::InProgress->value]);
        $milestones->markDone($production);

        // Tasks: one being checked, one past due (→ OVER).
        $tasks = app(TaskService::class);
        $checking = $tasks->create($project, [
            'milestone_id' => $production->id, 'title' => 'Rakit kabinet kitchen set', 'assignee_id' => $this->tukang->id,
            'due_date' => now()->addDay()->toDateString(), 'priority' => 'HIGH', 'rate_per_task' => 200_000,
        ], $this->pm);
        $tasks->updateStatus($checking, ['status' => 'PENGECEKAN'], $this->tukang);
        $tasks->create($project, [
            'milestone_id' => $late->id, 'title' => 'Pasang rangka hollow', 'assignee_id' => $this->tukang->id,
            'due_date' => now()->subDays(9)->toDateString(), 'priority' => 'MEDIUM', 'rate_per_task' => 150_000,
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
            'phone' => '08127777'.str_pad((string) $this->contact, 4, '0', STR_PAD_LEFT),
            'source' => 'Instagram',
            'priority' => 'WARM',
            'category' => 'RESIDENTIAL',
            'city_id' => City::idFor('Pekanbaru'),
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
     * requested · draft · submitted · ready (PM approved, with Marketing) ·
     * sent · approved (the client approved on the link).
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

        $steps = ['requested', 'draft', 'submitted', 'ready', 'sent', 'approved'];
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

        if (! $reached('ready')) {
            return $quotation->fresh();
        }

        // Sprint 23 K8/K10 — the PM's approval hands it straight to Marketing.
        $this->approveAll($quotation->fresh(), $this->pm);

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
        $this->invoices->submitProof($invoice, ['payment_proof_url' => 'https://drive.google.com/demo-bukti-'.$invoice->id], $this->marketing);
        $this->invoices->verify($invoice, ['bank_account_id' => BankAccount::firstOrFail()->id, 'paid_date' => now()->toDateString()], $this->finance);
    }

    // ── Sprint 24: the execution flow, one project per phase ────────────

    /**
     * Sprint 37 — every phase on the same RAB (Kitchen Set Custom · Partisi
     * Ruangan · Instalasi Listrik & Lampu) and the same work plan
     * (ExecutionFlowDemo::workPlan(): timelines holding their RAB items,
     * daily targets of the real Tukang, a Kepala Tim per item), placed at a
     * different moment: each step dated on its own day (Gambar Kerja → TM →
     * DP → targets → pengecekan on Friday / Saturday → Berangkat).
     */
    private function executionFlow(): void
    {
        $payments = app(SupervisorPaymentService::class);
        $reports = app(WeeklyReportService::class);
        $bank = BankAccount::where('is_active', true)->firstOrFail()->id;
        $today = now()->startOfDay();
        $at = fn (int $day, int $hour = 10) => Carbon::setTestNow($today->copy()->addDays($day)->setTime($hour, 0));
        // The Tukang reports every target dated in [from, to) "Selesai" on its day; the Pengawas approves those before `$checkedBefore`.
        $work = function (array $planned, int $from, int $to, int $checkedBefore) use ($today) {
            foreach ($planned as ['task' => $task, 'date' => $date]) {
                $day = (int) $today->diffInDays($date, false);

                if ($day >= $from && $day < $to) {
                    ExecutionFlowDemo::finishTarget($task, $date, $this->qa, approve: $day < $checkedBefore);
                }
            }
        };
        // The Pengawas's pengecekan: + % per RAB item, then the PM approves and Finance pays (`decide` false leaves it waiting).
        $check = function (Project $project, string $notes, array $percents, bool $decide = true) use ($reports, $payments, $bank) {
            $items = ExecutionFlowDemo::itemIds($project);
            $report = $reports->submit($project, [
                'notes' => $notes,
                'links' => ['https://drive.google.com/drive/folders/demo-foto-pengecekan'],
                'progress' => collect($percents)->map(fn (int $percent, string $item) => ['item_id' => $items[$item], 'percent' => $percent])->values()->all(),
            ], $this->qa);

            if ($decide) {
                $report = $reports->decide($report, true, null, null, $this->pm);
                $payments->pay($report->payment()->firstOrFail(), $bank, $this->finance);
            }
        };

        // The PM's "Tandai Selesai" on a finished timeline, on its last day (QA per item: it simply closes).
        $close = function (Milestone $timeline) use ($today, $at) {
            $at((int) $today->diffInDays($timeline->target_date, false), 17);
            app(MilestoneService::class)->markDone($timeline->fresh());
        };

        // 1. Just opened — its Gambar Kerja waits for the Kepala Desain.
        $this->openedProject('Rina Marlina', startedDaysAgo: 0, running: false);

        // 2. Gambar Kerja done — the PM runs the Technical Meeting (timelines already drafted, starting in a week).
        $meeting = $this->openedProject('Bambang Sutrisno', startedDaysAgo: 6, running: false);
        $at(-1);
        ExecutionFlowDemo::finishWorkingDrawing($meeting);
        Carbon::setTestNow();
        ExecutionFlowDemo::workPlan($meeting, 7);

        // 3. TM done, daily targets planned by the Pengawas, DP asked — waiting for the PM.
        $preparing = $this->openedProject('Siska Amelia', startedDaysAgo: 10, running: false);
        $plan = ExecutionFlowDemo::workPlan($preparing, 3);
        $at(-5);
        ExecutionFlowDemo::finishWorkingDrawing($preparing);
        $at(-1);
        $preparing = ExecutionFlowDemo::holdMeeting($preparing, $this->pm);
        ExecutionFlowDemo::assignLeads($preparing, $this->qa);
        ExecutionFlowDemo::plannedTargets($preparing, $plan, $today->copy()->addDays(3), $this->qa);
        Carbon::setTestNow();
        ExecutionFlowDemo::requestDp($preparing, $this->pm, approve: false);

        // 4. In production for 10 days: DP paid, last week's pengecekan approved & paid
        // (Sprint 36 — sent on Friday); targets checked off, the latest ones waiting to
        // be checked; this week's pengecekan is a draft (PengawasPreviewSeeder sends
        // one target back). Partisi is behind its timeline. Two Pengawas (Sprint 29 Sub 06).
        $producing = $this->openedProject('Hendra Gunawan', startedDaysAgo: 20, running: false);
        $plan = ExecutionFlowDemo::workPlan($producing, -10);
        $at(-15);
        ExecutionFlowDemo::finishWorkingDrawing($producing);
        $at(-11);
        $producing = ExecutionFlowDemo::holdMeeting($producing, $this->pm);
        ExecutionFlowDemo::assignLeads($producing, $this->qa);
        $planned = ExecutionFlowDemo::plannedTargets($producing, $plan, $today->copy()->subDays(10), $this->qa);
        $at(-10, 8);
        $producing = ExecutionFlowDemo::requestDp($producing, $this->pm);
        $payments->pay($producing->supervisorPayments()->firstOrFail(), $bank, $this->finance);

        $friday = (int) $today->diffInDays($today->copy()->subWeek()->startOfWeek(Carbon::MONDAY)->addDays(4), false);
        $work($planned, -10, $friday, $friday);
        $at($friday);
        $check($producing, 'Badan kabinet bawah kitchen set selesai dirakit, rangka & panel partisi terpasang. Bahan cukup sampai minggu depan.', [
            'Kitchen Set Custom' => 30,
            'Partisi Ruangan' => 60,
        ]);
        $work($planned, $friday, 0, -3);
        $close($plan['prep']);
        Carbon::setTestNow();
        app(WorkItemService::class)->saveDraft($producing, [
            'progress' => [
                ['item_id' => ExecutionFlowDemo::itemIds($producing)['Kitchen Set Custom'], 'percent' => 25, 'note' => 'Kabinet atas terpasang, pintu & engsel sebagian.'],
                ['item_id' => ExecutionFlowDemo::itemIds($producing)['Partisi Ruangan'], 'percent' => 30, 'note' => null],
            ],
            'notes' => 'Kabinet atas kitchen set selesai; partisi tinggal finishing tepi.',
        ], $this->qa);
        $producing->supervisors()->syncWithoutDetaching([User::where('username', 'qa2')->firstOrFail()->id]);

        // 5. Sprint 29 Sub 12 — everything installed and at 100 %: QA per item RAB.
        // Production (from a Monday) reached 71 % at the pengecekan on the Saturday
        // of the week before the last Friday / Saturday → the PM sent the team to
        // the site; installed in the days after; the last pengecekan (→ 100 %)
        // waits for the PM.
        $sendDay = in_array($today->dayOfWeekIso, [5, 6], true) ? 0 : (int) $today->diffInDays($today->copy()->previous(Carbon::FRIDAY), false);
        $departure = (int) $today->diffInDays($today->copy()->addDays($sendDay)->subWeek()->startOfWeek(Carbon::MONDAY)->addDays(5), false);
        $start = $departure - 12;
        $finishing = $this->openedProject('Dewi Lestari', startedDaysAgo: 10 - $start, running: false);
        $plan = ExecutionFlowDemo::workPlan($finishing, $start);
        $at($start - 5);
        ExecutionFlowDemo::finishWorkingDrawing($finishing);
        $at($start - 1);
        $finishing = ExecutionFlowDemo::holdMeeting($finishing, $this->pm);
        ExecutionFlowDemo::assignLeads($finishing, $this->qa);
        $planned = ExecutionFlowDemo::plannedTargets($finishing, $plan, $today->copy()->addDays($start), $this->qa);
        $at($start, 8);
        $finishing = ExecutionFlowDemo::requestDp($finishing, $this->pm);
        $payments->pay($finishing->supervisorPayments()->firstOrFail(), $bank, $this->finance);

        $work($planned, $start, $departure, PHP_INT_MAX);
        $at($departure);
        $check($finishing, 'Kitchen set & partisi selesai diproduksi di workshop, siap dikirim ke lokasi.', [
            'Kitchen Set Custom' => 80,
            'Partisi Ruangan' => 100,
        ]);
        $at($departure, 14);
        app(ProductionService::class)->approveDeparture($finishing, $this->pm);
        $work($planned, $departure, $sendDay, PHP_INT_MAX);
        $at($sendDay);
        $check($finishing, 'Kitchen set & partisi terpasang di lokasi, instalasi listrik & lampu selesai — siap diperiksa per item.', [
            'Kitchen Set Custom' => 20,
            'Instalasi Listrik & Lampu' => 100,
        ], decide: false);
        foreach (['prep', 'partisi', 'kitchen', 'install'] as $key) {
            $close($plan[$key]);
        }
        Carbon::setTestNow();

        $items = ExecutionFlowDemo::itemIds($finishing);
        $itemQa = app(WorkItemQaService::class);
        $itemQa->decide($finishing, ['decision' => 'LOLOS', 'item_ids' => [$items['Kitchen Set Custom'], $items['Instalasi Listrik & Lampu']], 'note' => 'Rapi, sesuai gambar kerja.'], null, $this->qa);
        $itemQa->decide($finishing, ['decision' => 'PERBAIKAN', 'item_ids' => [$items['Partisi Ruangan']], 'note' => 'Sambungan panel partisi kedua belum rapi di sisi kanan — rapikan sebelum serah terima.'], null, $this->qa);
    }

    /**
     * A project opened by the CEO from an approved RAB Proyek.
     *
     * @param  list<array<string, mixed>>|null  $terms
     */
    private function openedProject(string $client, ?array $terms = null, int $startedDaysAgo = 14, bool $running = true): Project
    {
        $quotation = $this->rab($this->lead($client), QuotationType::Proyek, 'approved', $terms ?? [
            ['label' => 'DP', 'percentage' => 30, 'trigger' => 'DI_MUKA'],
            ['label' => 'Pelunasan', 'percentage' => 70, 'trigger' => 'PROYEK_SELESAI'],
        ]);

        $project = app(ProjectService::class)->openFromQuotation(ProjectOpening::where('quotation_id', $quotation->id)->sole(), [
            'name' => 'Proyek '.$client,
            'pm_id' => $this->pm->id,
            // Sprint 23 K14 — the approving PM opens; Kepala Tim = the demo Tukang Budi.
            'team_lead_id' => User::where('username', 'budi')->value('id'),
            // Sprint 24 — the Pengawas (QA/QC) runs the execution flow.
            'supervisor_ids' => [$this->qa->id],
            'opened_on' => now()->subDays(max($startedDaysAgo, 0))->toDateString(),
            'start_date' => now()->subDays($startedDaysAgo)->toDateString(),
        ], $this->pm);

        // Sprint 24 — the older scenarios are projects already in production.
        return $running ? ExecutionFlowDemo::skipToProduction($project) : $project;
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
