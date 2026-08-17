<?php

namespace Database\Seeders;

use App\Enums\DesignStatus;
use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Enums\MilestoneStatus;
use App\Enums\TaskStatus;
use App\Models\BankAccount;
use App\Models\DailyTaskForm;
use App\Models\Lead;
use App\Models\Project;
use App\Models\QaForm;
use App\Models\Quotation;
use App\Models\User;
use App\Services\DesignService;
use App\Services\FamilyGatheringFundService;
use App\Services\FinanceTransactionService;
use App\Services\LeadService;
use App\Services\MilestoneService;
use App\Services\OvertimeService;
use App\Services\PenaltyService;
use App\Services\ProgressLogService;
use App\Services\QaFormService;
use App\Services\QuotationService;
use App\Services\TaskService;
use App\Services\TerminService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

/**
 * Walks the entire presales→execution→payroll business process (PRD §4,
 * .claude/plan/sprint-01.md through sprint-03.md) through the real
 * Service layer — not raw ::create() — so every PipelineLog,
 * QuotationApproval, DailyTaskForm and Penalty this produces is a
 * genuine side effect of the same business rules a real user's click
 * would trigger. Purpose: give a human a UI to click through and check
 * "does the business process actually work end to end", not just seed
 * rows. Local/staging/UAT only (PRD §11.1) — never run in production.
 */
class DemoDataSeeder extends Seeder
{
    private User $ceo;

    private User $marketing;

    private User $designer;

    private User $estimator;

    private User $pm;

    private User $finance;

    private User $qa;

    /** @var Collection<int, User> */
    private $fieldStaff;

    public function run(): void
    {
        $this->ceo = User::role('CEO')->firstOrFail();
        $this->marketing = User::role('MARKETING')->firstOrFail();
        $this->designer = User::role('DESIGNER')->firstOrFail();
        $this->estimator = User::role('ESTIMATOR')->firstOrFail();
        $this->pm = User::role('PM')->firstOrFail();
        $this->finance = User::role('FINANCE')->firstOrFail();
        $this->qa = User::role('QA')->firstOrFail();

        $this->fieldStaff = collect(['Rudi Hartono', 'Slamet Wijaya', 'Yanto Kurniawan'])
            ->map(function (string $name) {
                $email = str($name)->slug('.').'@daikuinterior.com';
                $staff = User::firstOrCreate(
                    ['email' => $email],
                    ['name' => $name, 'password' => Hash::make('password')],
                );
                $staff->assignRole('FIELD_STAFF');

                return $staff;
            });

        $leadService = app(LeadService::class);
        $designService = app(DesignService::class);
        $quotationService = app(QuotationService::class);

        // 1. Fresh pipeline — never touched Design/Quotation.
        $this->seedFollowUpLeads($leadService);

        // 2. In Design, not yet ACC'd by the client (no Quotation yet).
        $this->seedDesignInProgressLeads($leadService, $designService);

        // 3. Client ACC'd — Quotation exists, at various approval stages.
        $this->seedQuotationInProgressLeads($leadService, $designService, $quotationService);

        // 4. Fully closed — Quotation SENT_TO_CLIENT, deal confirmed,
        // Project created with Milestones/Tasks.
        $projects = $this->seedClosedDeals($leadService, $designService, $quotationService);

        // 5. Field ops — daily forms (some deliberately skipped so the
        // penalty job below has something real to catch), penalties,
        // family fund, overtime.
        $this->seedFieldOperations($projects);
    }

    private function seedFollowUpLeads(LeadService $leadService): void
    {
        $leadService->create([
            'client_name' => 'Siti Nurhaliza',
            'contact' => '0812-1111-0001',
            'source' => 'Instagram',
            'priority' => 'HOT',
            'category' => 'RESIDENTIAL',
            'city' => 'Jakarta Selatan',
            'assigned_to' => $this->marketing->id,
            'follow_up_date' => now()->subDay()->toDateString(), // overdue on purpose
            'notes' => 'Tertarik renovasi ruang tamu, minta follow-up ulang.',
        ], $this->marketing);

        $leadService->create([
            'client_name' => 'Ahmad Fauzi',
            'contact' => '0812-1111-0002',
            'source' => 'WhatsApp',
            'priority' => 'WARM',
            'category' => 'RESIDENTIAL',
            'city' => 'Bekasi',
            'assigned_to' => $this->marketing->id,
            'follow_up_date' => now()->addDays(3)->toDateString(),
        ], $this->marketing);

        $lost = $leadService->create([
            'client_name' => 'Rina Wijaya',
            'contact' => '0812-1111-0003',
            'source' => 'Website',
            'priority' => 'COLD',
            'category' => 'KOMERSIAL',
            'assigned_to' => $this->marketing->id,
        ], $this->marketing);

        $leadService->changeStatus($lost, [
            'status' => 'LOST',
            'lost_reason' => 'Budget klien tidak sesuai penawaran awal.',
        ], $this->marketing);
    }

    private function seedDesignInProgressLeads(LeadService $leadService, DesignService $designService): void
    {
        $specs = [
            ['name' => 'Bambang Sutrisno', 'source' => 'Referral/Rekomendasi', 'design_status' => DesignStatus::Brief, 'jenis' => 'RUANG_TAMU_TV'],
            ['name' => 'Dewi Anggraini', 'source' => 'TikTok', 'design_status' => DesignStatus::Desain, 'jenis' => 'KAMAR_SET'],
            ['name' => 'Hendra Gunawan', 'source' => 'Marketplace', 'design_status' => DesignStatus::WaitingAccDesain, 'jenis' => 'KANTOR'],
        ];

        foreach ($specs as $i => $spec) {
            $lead = $leadService->create([
                'client_name' => $spec['name'],
                'contact' => '0812-2222-000'.($i + 1),
                'source' => $spec['source'],
                'priority' => 'WARM',
                'assigned_to' => $this->marketing->id,
            ], $this->marketing);

            $leadService->changeStatus($lead, ['status' => 'DEAL_DESAIN', 'note' => 'Klien setuju lanjut ke tahap desain.'], $this->marketing);

            $design = $designService->create($lead, [
                'pic_id' => $this->designer->id,
                'jenis_project' => $spec['jenis'],
                'target_hari' => 14,
                'start_date' => now()->subDays(3)->toDateString(),
                'brief_note' => 'Klien minta gaya minimalis modern.',
            ]);

            // Walk the status forward to wherever this lead's demo stage
            // needs to land — plain field update (DesignService::update()
            // has no validated transition graph, see its docblock).
            if ($spec['design_status'] !== DesignStatus::Brief) {
                $designService->update($design, [
                    'pic_id' => $this->designer->id,
                    'jenis_project' => $spec['jenis'],
                    'status' => $spec['design_status']->value,
                    'target_hari' => 14,
                    'start_date' => now()->subDays(3)->toDateString(),
                ]);
            }
        }
    }

    private function seedQuotationInProgressLeads(LeadService $leadService, DesignService $designService, QuotationService $quotationService): void
    {
        $specs = [
            ['name' => 'Maya Sari', 'source' => 'Iklan Sosmed', 'quotation_stage' => 'draft'],
            ['name' => 'Yusuf Pratama', 'source' => 'Existing', 'quotation_stage' => 'submitted'],
            ['name' => 'Indah Permata', 'source' => 'Instagram', 'quotation_stage' => 'ceo_review'],
        ];

        foreach ($specs as $i => $spec) {
            [, $quotation] = $this->openAccdQuotation($leadService, $designService, $spec['name'], $spec['source'], '0812-3333-000'.($i + 1));

            $quotationService->replaceItems($quotation, [
                ['description' => 'Kitchen Set Custom', 'qty' => 1, 'unit' => 'set', 'unit_price' => 18_000_000],
                ['description' => 'Lemari Pakaian 2 Pintu', 'qty' => 2, 'unit' => 'unit', 'unit_price' => 4_500_000],
                ['description' => 'Meja & Kursi Makan', 'qty' => 1, 'unit' => 'set', 'unit_price' => 6_000_000],
            ]);

            if ($spec['quotation_stage'] === 'draft') {
                continue;
            }

            $quotationService->submit($quotation);

            if ($spec['quotation_stage'] === 'submitted') {
                continue;
            }

            $quotationService->ceoDecision($quotation, 'approve', $this->ceo);
        }
    }

    /**
     * @return array{0: array{lead: Lead, project: Project}, 1: array{lead: Lead, project: Project}}
     */
    private function seedClosedDeals(LeadService $leadService, DesignService $designService, QuotationService $quotationService): array
    {
        $results = [];

        foreach ([
            ['name' => 'Budi Santoso', 'source' => 'Instagram', 'value' => 42_000_000],
            ['name' => 'Citra Lestari', 'source' => 'Website', 'value' => 28_000_000],
        ] as $i => $spec) {
            [$lead, $quotation] = $this->openAccdQuotation($leadService, $designService, $spec['name'], $spec['source'], '0812-4444-000'.($i + 1));

            $quotationService->replaceItems($quotation, [
                ['description' => 'Kitchen Set Custom', 'qty' => 1, 'unit' => 'set', 'unit_price' => 20_000_000],
                ['description' => 'Partisi Ruangan', 'qty' => 3, 'unit' => 'unit', 'unit_price' => 2_500_000],
                ['description' => 'Pengecatan Interior', 'qty' => 1, 'unit' => 'paket', 'unit_price' => 8_000_000],
            ]);
            $quotationService->submit($quotation);
            $quotationService->ceoDecision($quotation, 'approve', $this->ceo);
            $quotationService->pmDecision($quotation, 'approve', $this->pm);

            $leadService->confirmDeal($lead, [
                'name' => 'Proyek '.$spec['name'],
                'pm_id' => $this->pm->id,
                'start_date' => now()->subDays(7)->toDateString(),
                'contract_value' => $spec['value'],
            ], $this->marketing);

            $project = Project::where('lead_id', $lead->id)->firstOrFail();

            $results[] = ['lead' => $lead->fresh(), 'project' => $project];
        }

        return $results;
    }

    /**
     * Shared happy-path: create lead → DEAL_DESAIN → design brief →
     * WAITING_ACC_DESAIN → Client ACC (which itself opens the Quotation,
     * DesignService::clientAcc()).
     */
    private function openAccdQuotation(LeadService $leadService, DesignService $designService, string $name, string $source, string $contact): array
    {
        $lead = $leadService->create([
            'client_name' => $name,
            'contact' => $contact,
            'source' => $source,
            'priority' => 'HOT',
            'assigned_to' => $this->marketing->id,
        ], $this->marketing);

        $leadService->changeStatus($lead, ['status' => 'DEAL_DESAIN'], $this->marketing);

        $design = $designService->create($lead, [
            'pic_id' => $this->designer->id,
            'jenis_project' => 'KITCHEN_SET',
            'target_hari' => 10,
            'start_date' => now()->subDays(10)->toDateString(),
        ]);

        $designService->update($design, [
            'pic_id' => $this->designer->id,
            'jenis_project' => 'KITCHEN_SET',
            'status' => DesignStatus::WaitingAccDesain->value,
            'target_hari' => 10,
            'start_date' => now()->subDays(10)->toDateString(),
        ]);

        $design = $designService->clientAcc($design->fresh(), $this->marketing);
        $quotation = Quotation::where('lead_id', $lead->id)->firstOrFail();

        return [$lead, $quotation];
    }

    /**
     * @param  array<int, array{lead: Lead, project: Project}>  $projects
     */
    private function seedFieldOperations(array $projects): void
    {
        $milestoneService = app(MilestoneService::class);
        $taskService = app(TaskService::class);

        $tasksByStaff = collect();

        foreach ($projects as $index => $entry) {
            /** @var Project $project */
            $project = $entry['project'];
            $isFirstProject = $index === 0;

            // Project 1's milestones are walked through the *real* QA flow
            // below (walkFirstProjectThroughQa()) instead of having their
            // final status written directly here — so start every one of
            // them at the migration default (PENDING).
            $milestoneSpecs = $isFirstProject
                ? [
                    ['name' => '3D Design', 'offset' => -10],
                    ['name' => 'Produksi', 'offset' => 5],
                    ['name' => 'Instalasi & Finishing', 'offset' => 20],
                ]
                : [
                    ['name' => '3D Design', 'status' => MilestoneStatus::InProgress, 'offset' => 3],
                    ['name' => 'Produksi', 'status' => MilestoneStatus::Pending, 'offset' => 15],
                ];

            $milestones = [];

            foreach ($milestoneSpecs as $spec) {
                $milestone = $milestoneService->create($project, [
                    'name' => $spec['name'],
                    'target_date' => now()->addDays($spec['offset'])->toDateString(),
                ]);

                if (! $isFirstProject && $spec['status'] !== MilestoneStatus::Pending) {
                    $milestoneService->update($milestone, [
                        'name' => $spec['name'],
                        'target_date' => now()->addDays($spec['offset'])->toDateString(),
                        'status' => $spec['status']->value,
                    ]);
                }

                $milestones[] = $milestone;
            }

            $taskSpecs = $isFirstProject
                ? [
                    ['title' => 'Pasang kitchen set', 'status' => TaskStatus::Done, 'due_offset' => -5, 'milestone' => 0],
                    ['title' => 'Finishing cat dinding', 'status' => TaskStatus::Done, 'due_offset' => -3, 'milestone' => 0],
                    ['title' => 'Rakit lemari pakaian', 'status' => TaskStatus::OnProgress, 'due_offset' => 2, 'milestone' => 1],
                    ['title' => 'Pasang partisi ruangan', 'status' => TaskStatus::OnProgress, 'due_offset' => 4, 'milestone' => 1],
                    ['title' => 'Cek kualitas instalasi listrik', 'status' => TaskStatus::Pending, 'due_offset' => -1, 'milestone' => 1], // overdue on purpose
                ]
                : [
                    ['title' => 'Survey lokasi awal', 'status' => TaskStatus::Done, 'due_offset' => -2, 'milestone' => 0],
                    ['title' => 'Pasang partisi ruangan', 'status' => TaskStatus::OnProgress, 'due_offset' => 3, 'milestone' => 0],
                    ['title' => 'Pengecatan interior', 'status' => TaskStatus::Pending, 'due_offset' => 10, 'milestone' => 0],
                ];

            foreach ($taskSpecs as $taskIndex => $spec) {
                $assignee = $this->fieldStaff[$taskIndex % $this->fieldStaff->count()];

                $task = $taskService->create($project, [
                    'milestone_id' => $milestones[$spec['milestone']]->id,
                    'title' => $spec['title'],
                    'assignee_id' => $assignee->id,
                    'due_date' => now()->addDays($spec['due_offset'])->toDateString(),
                    'priority' => 'MEDIUM',
                    'rate_per_task' => 150_000,
                ], $this->pm);

                if ($spec['status'] !== TaskStatus::Pending) {
                    $taskService->updateStatus($task, ['status' => $spec['status']->value], $assignee);
                }

                if ($spec['status'] === TaskStatus::OnProgress) {
                    $tasksByStaff->push(['task' => $task->fresh(), 'staff' => $assignee]);
                }
            }

            if ($isFirstProject) {
                $this->walkFirstProjectThroughQa($milestoneService, $milestones);
            }
        }

        // Daily forms — submitted for every ONPROGRESS task *except* one,
        // so PenaltyService::runDailyCheck() below has a real gap to
        // catch (not a manufactured one) instead of always finding
        // nothing.
        $today = now('Asia/Jakarta')->toDateString();

        foreach ($tasksByStaff->slice(0, -1) as $entry) {
            DailyTaskForm::create([
                'task_id' => $entry['task']->id,
                'staff_id' => $entry['staff']->id,
                'work_date' => $today,
                'status_update' => TaskStatus::OnProgress->value,
                'notes' => 'Progres sesuai rencana, tidak ada kendala.',
                'submitted_at' => now(),
            ]);
        }

        app(PenaltyService::class)->runDailyCheck();

        app(FamilyGatheringFundService::class)->recordExpense([
            'amount' => 150_000,
            'description' => 'Konsumsi acara gathering internal Q3 2026',
        ], $this->finance);

        $this->seedOvertimeRequests($projects);
        $this->seedProgressLogsAndTermins($projects[0]['project']);
        $this->seedFinanceTransactions($projects[0]['project']);
    }

    /**
     * Walks the first project's first two milestones through the real QA
     * pipeline (PRD §4.6/§6.3) instead of writing their final status
     * directly — so the demo shows a genuine APPROVE (milestone 1,
     * unlocking its Termin below) and a genuine REJECT (milestone 2,
     * left mid-fix at IN_PROGRESS with rejection_count=1) rather than a
     * bypassed shortcut.
     */
    private function walkFirstProjectThroughQa(MilestoneService $milestoneService, array $milestones): void
    {
        $qaFormService = app(QaFormService::class);

        $first = $milestones[0];
        $milestoneService->update($first, [
            'name' => $first->name,
            'target_date' => $first->target_date,
            'status' => MilestoneStatus::InProgress->value,
        ]);
        $milestoneService->markDone($first);
        $firstQaForm = QaForm::where('milestone_id', $first->id)->firstOrFail();
        $qaFormService->review($firstQaForm, 'approve', [
            ['label' => 'Hasil pekerjaan sesuai spesifikasi/desain', 'passed' => true, 'note' => null],
            ['label' => 'Kerapian dan kebersihan area kerja', 'passed' => true, 'note' => null],
        ], 'Semua item checklist terpenuhi.', $this->qa);

        // advanceNextMilestone() (inside QaFormService::review()) already
        // moved $milestones[1] PENDING → IN_PROGRESS as a side effect of
        // the approval above.
        $second = $milestones[1]->fresh();
        $milestoneService->markDone($second);
        $secondQaForm = QaForm::where('milestone_id', $second->id)->firstOrFail();
        $qaFormService->review($secondQaForm, 'reject', [
            ['label' => 'Hasil pekerjaan sesuai spesifikasi/desain', 'passed' => false, 'note' => 'Sambungan masih terlihat'],
        ], 'Instalasi belum rapi — tolong diperbaiki dan ajukan ulang.', $this->qa);
    }

    /**
     * Progress Log timeline + Termin schedule for the first project — both
     * new Sprint 4 modules. Logged only after the QA reject above resolves
     * (milestone 2 is IN_PROGRESS, not QA_WAITING) since ProgressLogService
     * blocks new entries while any milestone is waiting on QA.
     */
    private function seedProgressLogsAndTermins(Project $project): void
    {
        $progressLogService = app(ProgressLogService::class);

        $progressLogService->create($project, [
            'percentage' => 25,
            'description' => 'Kitchen set dan finishing cat dinding selesai (3D Design approved QA).',
            'log_date' => now()->subDays(4)->toDateString(),
        ], $this->pm);

        $progressLogService->create($project, [
            'percentage' => 55,
            'description' => 'Produksi berjalan — QA meminta perbaikan instalasi sebelum lanjut ke tahap berikutnya.',
            'ref_urls' => ['https://drive.google.com/demo-progress-produksi'],
            'log_date' => now()->toDateString(),
        ], $this->pm);

        $terminService = app(TerminService::class);
        $bankAccount = BankAccount::first();
        $milestones = $project->milestones()->orderBy('order')->get();

        $dpTermin = $terminService->create($project, [
            'milestone_id' => $milestones[0]->id, // '3D Design' — already COMPLETED, so unlocked.
            'percentage' => 30,
            'bank_account_id' => $bankAccount?->id,
        ]);
        $terminService->markPaid($dpTermin, $this->finance);

        // Still locked — milestones[1] ('Produksi') is IN_PROGRESS, not
        // COMPLETED, per TerminService::markPaid()'s docblock.
        $terminService->create($project, [
            'milestone_id' => $milestones[1]->id,
            'percentage' => 40,
            'bank_account_id' => $bankAccount?->id,
        ]);
    }

    /** A manual expense + one staff wage payment — "Finance – Transaction" row (PRD §7.1). */
    private function seedFinanceTransactions(Project $project): void
    {
        $bankAccount = BankAccount::first();

        app(FinanceTransactionService::class)->create([
            'project_id' => $project->id,
            'bank_account_id' => $bankAccount?->id,
            'type' => FinanceTransactionType::Expense->value,
            'kategori' => FinanceCategory::BeliBahan->value,
            'amount' => 3_500_000,
            'description' => 'Pembelian material kayu jati untuk kitchen set',
            'date' => now()->subDays(6)->toDateString(),
        ], $this->finance);

        $doneTask = $project->tasks()->where('status', TaskStatus::Done->value)->first();

        if ($doneTask) {
            app(FinanceTransactionService::class)->payStaffForTask($doneTask, $this->finance);
        }
    }

    /**
     * @param  array<int, array{lead: Lead, project: Project}>  $projects
     */
    private function seedOvertimeRequests(array $projects): void
    {
        $overtimeService = app(OvertimeService::class);
        $project = $projects[0]['project'];

        // Left at PENDING on purpose — nothing further to do with it.
        $overtimeService->create([
            'project_id' => $project->id,
            'hours' => 3,
            'rate_per_hour' => 30_000,
            'work_date' => now()->toDateString(),
            'reason' => 'Kejar deadline instalasi kitchen set.',
        ], $this->fieldStaff[0]);

        $awaitingFinance = $overtimeService->create([
            'project_id' => $project->id,
            'hours' => 2.5,
            'rate_per_hour' => 30_000,
            'work_date' => now()->subDay()->toDateString(),
            'reason' => 'Lembur rakit lemari pakaian.',
        ], $this->fieldStaff[1]);
        $overtimeService->pmDecision($awaitingFinance, 'approve', $this->pm);

        $completed = $overtimeService->create([
            'project_id' => $project->id,
            'hours' => 4,
            'rate_per_hour' => 30_000,
            'work_date' => now()->subDays(2)->toDateString(),
            'reason' => 'Lembur finishing cat dinding sebelum QA.',
        ], $this->fieldStaff[0]);
        $overtimeService->pmDecision($completed, 'approve', $this->pm);
        $overtimeService->financeDecision($completed, 'approve', $this->finance);

        $rejected = $overtimeService->create([
            'project_id' => $project->id,
            'hours' => 1.5,
            'rate_per_hour' => 30_000,
            'work_date' => now()->subDays(3)->toDateString(),
            'reason' => 'Lembur tanpa koordinasi PM sebelumnya.',
        ], $this->fieldStaff[2]);
        $overtimeService->pmDecision($rejected, 'reject', $this->pm, 'Tidak ada bukti koordinasi sebelum lembur diajukan.');
    }
}
