<?php

namespace Database\Seeders;

use App\Enums\DesignStatus;
use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Enums\MilestoneStatus;
use App\Enums\TaskStatus;
use App\Models\Asset;
use App\Models\BankAccount;
use App\Models\DailyTaskForm;
use App\Models\Lead;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\MaterialSynonym;
use App\Models\Penalty;
use App\Models\Project;
use App\Models\QaForm;
use App\Models\Quotation;
use App\Models\RevenueTarget;
use App\Models\Task;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AssetInstallmentService;
use App\Services\DesignService;
use App\Services\EmployeeService;
use App\Services\FamilyGatheringFundService;
use App\Services\FinanceTransactionService;
use App\Services\FundTransferService;
use App\Services\LeadService;
use App\Services\MaterialCatalogService;
use App\Services\MaterialRequestService;
use App\Services\MilestoneService;
use App\Services\OvertimeService;
use App\Services\PayrollService;
use App\Services\PenaltyCollectionService;
use App\Services\PenaltyService;
use App\Services\ProgressLogService;
use App\Services\ProjectMaterialService;
use App\Services\QaFormService;
use App\Services\QuotationService;
use App\Services\StaffLoanService;
use App\Services\StaffPaymentService;
use App\Services\StockService;
use App\Services\SupplierDebtService;
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

        // 4b. Staff loans + supplier debts (PRD §4.7). Loans go in before
        // field operations so the demo wage payment there already shows
        // an automatic installment deduction.
        $this->seedStaffLoansAndSupplierDebts($projects[0]['project']);

        // 5. Field ops — daily forms (some deliberately skipped so the
        // penalty job below has something real to catch), penalties,
        // family fund, overtime.
        $this->seedFieldOperations($projects);

        // 6. Logistics — material master, stock received, material planned
        // and issued to the first project (one material left below its
        // minimum so the low-stock badge/alert has something real), assets.
        $this->seedLogistics($projects[0]['project']);

        // 6b. Gaji karyawan tetap + cicilan aset (PRD §4.7, Sprint 9).
        $this->seedPayrollAndAssetInstallments();

        // 7. CEO revenue targets for the Analytics "Revenue vs Target" chart.
        foreach (range(5, 0) as $monthsAgo) {
            RevenueTarget::create([
                'month' => now()->startOfMonth()->subMonths($monthsAgo)->format('Y-m'),
                'target_amount' => 60_000_000 + (5 - $monthsAgo) * 5_000_000,
                'set_by' => $this->ceo->id,
            ]);
        }
    }

    /**
     * PRD §4.8 through the real services: StockService for every stock
     * change (so the ledger and `materials.stock` reconcile) and
     * ProjectMaterialService for project material lines (Sprint 11).
     */
    private function seedLogistics(Project $project): void
    {
        $logistics = User::role('LOGISTICS')->firstOrFail();
        $stockService = app(StockService::class);
        $projectMaterials = app(ProjectMaterialService::class);

        // [base name, spec, brand, unit, category prefix, cost, sell, min, received] —
        // through MaterialCatalogService, so codes and match keys are real (Sprint 11 §5.5).
        $catalog = [
            ['Triplek Meranti', '18 mm 122×244', null, 'lbr', 'KYP', 185_000, 240_000, 10, 40],
            ['HPL', 'Walnut', 'Taco', 'lbr', 'FIN', 145_000, 195_000, 8, 25],
            ['MDF', '12 mm', null, 'lbr', 'KYP', 120_000, 155_000, 10, 30],
            ['Engsel Sendok', 'Soft Close', null, 'pcs', 'HDW', 18_000, 28_000, 50, 200],
            ['Rel Laci Tandem', '45 cm', null, 'set', 'HDW', 95_000, 135_000, 15, 18],
            ['Lem Kayu', '1 kg', 'Crossbond', 'kg', 'BHP', 42_000, 55_000, 5, 12],
        ];
        $catalogService = app(MaterialCatalogService::class);

        $materials = collect($catalog)->map(function (array $row) use ($stockService, $logistics, $catalogService) {
            [$base, $spec, $brand, $unit, $prefix, $cost, $sell, $min, $received] = $row;

            $material = $catalogService->create([
                'material_category_id' => MaterialCategory::where('code_prefix', $prefix)->value('id'),
                'base_name' => $base,
                'spec' => $spec,
                'brand' => $brand,
                'unit_id' => $this->unit($unit),
                'cost_price' => $cost,
                'sell_price' => $sell,
                'min_stock' => $min,
                // Items of one demo catalog look alike on purpose (two sheet goods).
                'similar_reason' => 'Data demo',
            ], $logistics);

            $stockService->stockIn($material, [
                'qty' => $received,
                'movement_date' => now()->subDays(14)->toDateString(),
                'note' => 'Stok awal gudang',
            ], $logistics);

            return $material;
        });

        // Planned from the warehouse (GUDANG), partly issued by Logistics,
        // and the PM records what the crew actually used.
        foreach ([[0, 24, 14, 12], [1, 12, 6, 6], [3, 60, 40, 32], [4, 10, 6, 6]] as [$index, $planned, $issued, $used]) {
            $line = $projectMaterials->plan($project, [
                'material_id' => $materials[$index]->id,
                'source' => 'GUDANG',
                'qty_planned' => $planned,
            ], $logistics);
            $projectMaterials->issue($line, [
                'qty' => $issued,
                'movement_date' => now()->subDays(3)->toDateString(),
                'note' => 'Produksi kabinet',
            ], $logistics);
            $projectMaterials->recordUsage($line, ['qty' => $used], $project->pm);
        }

        // Sprint 11 §5.1's story: 19 sheets of MDF bought for the project,
        // 17 used, the 2 left over returned to the warehouse.
        $bought = $projectMaterials->plan($project, [
            'material_id' => $materials[2]->id,
            'source' => 'PEMBELIAN',
            'qty_planned' => 19,
            'vendor_id' => $this->vendor('Toko Sumber Kayu'),
        ], $project->pm);
        $projectMaterials->recordPurchase($bought, [
            'qty' => 19,
            'unit_price' => 125_000,
            'purchase_date' => now()->subDays(5)->toDateString(),
        ], $project->pm);
        $projectMaterials->recordUsage($bought, ['qty' => 17], $project->pm);
        $projectMaterials->returnToWarehouse($bought, [
            'qty' => 2,
            'movement_date' => now()->subDay()->toDateString(),
            'note' => 'Sisa potongan utuh',
        ], $logistics);

        $this->seedMaterialRequests($project, $logistics, $catalogService);

        // Rel Laci: 18 received − 6 issued = 12 < min 15 → low stock.

        $assets = [
            ['Mobil Pickup L300', 'Kendaraan', '2023-02-10', 185_000_000, 'GOOD', 'Workshop Utama'],
            ['Mesin Panel Saw', 'Mesin', '2022-07-01', 68_000_000, 'GOOD', 'Workshop Utama'],
            ['Kompresor Angin 2HP', 'Alat', '2021-11-15', 7_500_000, 'FAIR', 'Workshop Utama'],
            ['Bor Listrik Makita', 'Alat', '2024-01-20', 1_850_000, 'DAMAGED', 'Gudang Alat'],
        ];

        foreach ($assets as [$name, $category, $purchased, $value, $condition, $location]) {
            Asset::create([
                'name' => $name,
                'category' => $category,
                'purchase_date' => $purchased,
                'value' => $value,
                'condition' => $condition,
                'location' => $location,
            ]);
        }
    }

    private function seedFollowUpLeads(LeadService $leadService): void
    {
        // Sprint 12 decision #2: four follow-ups answered, FU-5 overdue — the
        // detail page suggests marking the lead Lost.
        $siti = $leadService->create([
            'client_name' => 'Siti Nurhaliza',
            'contact' => '0812-1111-0001',
            'first_contacted_at' => now()->subDays(30)->toDateString(),
            'source' => 'Instagram',
            'priority' => 'HOT',
            'category' => 'RESIDENTIAL',
            'city' => 'Pekanbaru',
            'address' => 'Jl. Tegal Sari No. 12, Pekanbaru',
            'maps_url' => 'https://maps.google.com/?q=Jl.+Tegal+Sari+Pekanbaru',
            'assigned_to' => $this->marketing->id,
            'follow_up_date' => now()->subDays(28)->toDateString(),
            'notes' => 'Tertarik renovasi ruang tamu, minta follow-up ulang.',
        ], $this->marketing);
        foreach ([21, 14, 7] as $daysAgo) {
            $leadService->addFollowUp($siti, ['scheduled_date' => now()->subDays($daysAgo)->toDateString()], $this->marketing);
        }
        foreach ($siti->followUps()->get() as $followUp) {
            $leadService->completeFollowUp($followUp, ['result_note' => 'Klien belum memutuskan, minta dihubungi lagi.']);
        }
        $leadService->addFollowUp($siti, ['scheduled_date' => now()->subDay()->toDateString()], $this->marketing); // FU-5, overdue

        // Decision #3: a survey outside Pekanbaru waits for the paid RAB Jasa Survey.
        $ahmad = $leadService->create([
            'client_name' => 'Ahmad Fauzi',
            'contact' => '0812-1111-0002',
            'first_contacted_at' => now()->subDays(2)->toDateString(),
            'source' => 'WhatsApp',
            'priority' => 'WARM',
            'category' => 'RESIDENTIAL',
            'city' => 'Bangkinang',
            'address' => 'Jl. Prof. M. Yamin, Bangkinang, Kampar',
            'assigned_to' => $this->marketing->id,
            'follow_up_date' => now()->addDays(3)->toDateString(),
        ], $this->marketing);
        $leadService->scheduleSurvey($ahmad, [
            'scheduled_at' => now()->addWeek()->setTime(10, 0)->toDateTimeString(),
            'is_outside_pekanbaru' => true,
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

        // PRD §4.2 "PIC & Sub-Staff" — one design worked on by a second designer.
        $subStaff = User::firstOrCreate(
            ['email' => 'lika@daikuinterior.com'],
            ['name' => 'Lika', 'password' => Hash::make('password')],
        );
        $subStaff->assignRole('DESIGNER');
        $design = Lead::where('client_name', 'Dewi Anggraini')->firstOrFail()->design;
        $designService->update($design, [
            'pic_id' => $this->designer->id,
            'status' => $design->status->value,
            'staff' => [['user_id' => $subStaff->id, 'role_note' => '3D modeling & render']],
        ]);
    }

    private function seedQuotationInProgressLeads(LeadService $leadService, DesignService $designService, QuotationService $quotationService): void
    {
        $specs = [
            ['name' => 'Maya Sari', 'source' => 'Iklan Sosmed', 'quotation_stage' => 'draft'],
            ['name' => 'Yusuf Pratama', 'source' => 'Existing', 'quotation_stage' => 'submitted'],
            ['name' => 'Indah Permata', 'source' => 'Instagram', 'quotation_stage' => 'ceo_review'],
            ['name' => 'Rina Kartika', 'source' => 'Website', 'quotation_stage' => 'revised'],
        ];

        foreach ($specs as $i => $spec) {
            [, $quotation] = $this->openAccdQuotation($leadService, $designService, $spec['name'], $spec['source'], '0812-3333-000'.($i + 1));

            $quotationService->replaceItems($quotation, [
                ['description' => 'Kitchen Set Custom', 'qty' => 1, 'unit_id' => $this->unit('set'), 'unit_price' => 18_000_000],
                ['description' => 'Lemari Pakaian 2 Pintu', 'qty' => 2, 'unit_id' => $this->unit('unit'), 'unit_price' => 4_500_000],
                ['description' => 'Meja & Kursi Makan', 'qty' => 1, 'unit_id' => $this->unit('set'), 'unit_price' => 6_000_000],
            ]);

            if ($spec['quotation_stage'] === 'draft') {
                continue;
            }

            $quotationService->submit($quotation);

            // v1 rejected by the CEO, revised and resubmitted as v2 — gives
            // "Riwayat Revisi" a real entry (PRD §4.3 "Versi Revisi").
            if ($spec['quotation_stage'] === 'revised') {
                $quotationService->ceoDecision($quotation, 'reject', $this->ceo, 'Harga kitchen set terlalu tinggi, turunkan ±10%.');
                $quotationService->replaceItems($quotation, [
                    ['description' => 'Kitchen Set Custom', 'qty' => 1, 'unit_id' => $this->unit('set'), 'unit_price' => 16_000_000],
                    ['description' => 'Lemari Pakaian 2 Pintu', 'qty' => 2, 'unit_id' => $this->unit('unit'), 'unit_price' => 4_500_000],
                    ['description' => 'Meja & Kursi Makan', 'qty' => 1, 'unit_id' => $this->unit('set'), 'unit_price' => 6_000_000],
                ]);
                $quotationService->submit($quotation);

                continue;
            }

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
                ['description' => 'Kitchen Set Custom', 'qty' => 1, 'unit_id' => $this->unit('set'), 'unit_price' => 20_000_000],
                ['description' => 'Partisi Ruangan', 'qty' => 3, 'unit_id' => $this->unit('unit'), 'unit_price' => 2_500_000],
                ['description' => 'Pengecatan Interior', 'qty' => 1, 'unit_id' => $this->unit('ls'), 'unit_price' => 8_000_000],
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

        $penaltyService = app(PenaltyService::class);
        $penaltyService->runDailyCheck();
        foreach ([3, 2] as $daysAgo) { // history so Slamet has several penalties
            $penaltyService->runDailyCheck(now('Asia/Jakarta')->subWeekdays($daysAgo));
        }

        // Sprint 9 decision #10 — penalties are paid manually: one of
        // Slamet's is paid in cash, the rest stay outstanding, and the fund
        // can only spend what was actually collected.
        $slamet = $this->fieldStaff->firstWhere('name', 'Slamet Wijaya');
        $slametUnpaid = Penalty::where('staff_id', $slamet->id)->unpaid()->orderBy('date_occurred')->get();
        $bankAccountId = BankAccount::where('is_active', true)->value('id');

        if ($slametUnpaid->isNotEmpty() && $bankAccountId) {
            app(PenaltyCollectionService::class)->recordPayment($slamet, [
                'penalty_ids' => [$slametUnpaid->first()->id],
                'bank_account_id' => $bankAccountId,
                'date' => now()->toDateString(),
                'note' => 'Dibayar tunai di kantor',
            ], $this->finance);

            app(FamilyGatheringFundService::class)->recordExpense([
                'amount' => 30_000,
                'description' => 'Konsumsi rapat persiapan gathering internal Q3 2026',
                'bank_account_id' => $bankAccountId,
                'date' => now()->toDateString(),
            ], $this->finance);
        }

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

        // Unlinked termin with only a DP received — the "Dibayar Sebagian" state.
        if ($bankAccount) {
            $partialTermin = $terminService->create($project, [
                'percentage' => 10,
                'bank_account_id' => $bankAccount->id,
            ]);
            $terminService->recordPayment($partialTermin, [
                'type' => TerminService::PAYMENT_DP,
                'amount' => round((float) $partialTermin->amount / 2, 2),
                'bank_account_id' => $bankAccount->id,
                'paid_date' => now()->toDateString(),
            ], $this->finance);
        }
    }

    /**
     * PRD §4.7 "Pinjaman Tukang" + "Hutang Supplier" through their
     * services: one loan partly repaid in cash, one untouched (the demo
     * wage payment deducts from it), one overdue unpaid debt, one
     * partially paid debt and one with no due date.
     */
    private function seedStaffLoansAndSupplierDebts(Project $project): void
    {
        $bank = BankAccount::first();

        if (! $bank) {
            return;
        }

        $loans = app(StaffLoanService::class);

        foreach ($this->fieldStaff->take(2) as $index => $staff) {
            $loan = $loans->create([
                'staff_id' => $staff->id,
                'amount' => $index === 0 ? 2_000_000 : 750_000,
                'installment_amount' => $index === 0 ? 250_000 : 150_000,
                'bank_account_id' => $bank->id,
                'description' => $index === 0 ? 'Kasbon biaya sekolah anak' : 'Kasbon berobat',
            ], $this->finance);

            if ($index === 0) {
                $loans->recordPayment($loan, [
                    'amount' => 500_000,
                    'paid_date' => now()->subDays(3)->toDateString(),
                    'bank_account_id' => $bank->id,
                    'note' => 'Bayar tunai',
                ], $this->finance);
            }
        }

        $debts = app(SupplierDebtService::class);

        $debts->create([
            'vendor_id' => $this->vendor('Kaca Jaya'),
            'total_amount' => 4_500_000,
            'project_id' => $project->id,
            'due_date' => now()->subDays(10)->toDateString(),
            'description' => 'Kaca tempered 8mm partisi',
        ], $this->finance);

        $ideal = $debts->create([
            'vendor_id' => $this->vendor('Ideal'),
            'total_amount' => 12_000_000,
            'project_id' => $project->id,
            'due_date' => now()->addWeeks(3)->toDateString(),
        ], $this->finance);
        $debts->recordPayment($ideal, [
            'amount' => 5_000_000,
            'paid_date' => now()->subDays(3)->toDateString(),
            'bank_account_id' => $bank->id,
            'note' => 'Cicilan pertama',
        ], $this->finance);

        $debts->create([
            'vendor_id' => $this->vendor('HPL Makmur'),
            'total_amount' => 2_750_000,
        ], $this->finance);
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

        if ($doneTask && $bankAccount) {
            app(StaffPaymentService::class)->pay($doneTask, $bankAccount->id, $this->finance);
        }

        // Pindah Dana between two company accounts (Sprint 9 decision #5).
        $accounts = BankAccount::where('is_active', true)->orderBy('id')->take(2)->get();
        if ($accounts->count() === 2) {
            app(FundTransferService::class)->transfer([
                'from_bank_account_id' => $accounts[0]->id,
                'to_bank_account_id' => $accounts[1]->id,
                'amount' => 10_000_000,
                'date' => now()->subDays(2)->toDateString(),
                'description' => 'Top up rekening operasional',
            ], $this->finance);
        }
    }

    /** PRD §4.7 "Gaji Karyawan Tetap" + "Aset & Cicilan" through their services. */
    private function seedPayrollAndAssetInstallments(): void
    {
        $bank = BankAccount::where('is_active', true)->first();
        if (! $bank) {
            return;
        }

        $employees = app(EmployeeService::class);
        $payroll = app(PayrollService::class);
        $lastMonth = now()->subMonthNoOverflow();

        // SDM (Sprint 10): positions come from the Divisi → Jabatan master;
        // demo accounts are linked so KPI auto-indicators and the "Milik
        // Saya" pages have someone real to show.
        $this->call(OrganizationStructureSeeder::class);

        $staff = [
            ['Boy', 'Marketing', 4_500_000, 'marketing@daikuinterior.com'],
            ['Icha', 'Desainer Interior', 5_000_000, 'designer@daikuinterior.com'],
            ['Ami', 'Estimator', 5_000_000, 'estimator@daikuinterior.com'],
            ['Ibnu', 'Drafter', 4_250_000, null],
            ['Ilham', 'Admin Finance', 4_000_000, 'finance@daikuinterior.com'],
            ['Hesti', 'Admin Kantor', 3_750_000, null],
            ['Satria', 'Staf Gudang', 3_500_000, null],
            ['Rojab', 'Project Manager', 6_000_000, 'pm@daikuinterior.com'],
            ['Yola', 'Staf SDM', 4_250_000, 'hr@daikuinterior.com'],
        ];

        foreach ($staff as $i => [$name, $position, $salary, $email]) {
            $employee = $employees->create([
                'name' => $name,
                'position_id' => OrganizationStructureSeeder::position($position)->id,
                'base_salary' => $salary,
                'user_id' => $email ? User::where('email', $email)->value('id') : null,
                'bank_name' => 'BCA',
                'account_no' => '52'.str_pad((string) ($i + 1), 8, '0', STR_PAD_LEFT),
                'join_date' => now()->subYears(2)->subMonths($i)->startOfMonth()->toDateString(),
            ], $this->finance);

            // Last month paid for the first five; Hesti & Satria stay "Belum" to click through.
            if ($i < 5) {
                $payroll->pay($employee, [
                    'period' => $lastMonth->format('Y-m'),
                    'allowance' => $i === 0 ? 500_000 : 0,
                    'deduction' => $i === 3 ? 250_000 : 0,
                    'paid_at' => $lastMonth->copy()->endOfMonth()->toDateString(),
                    'bank_account_id' => $bank->id,
                    'note' => $i === 0 ? 'Bonus target' : ($i === 3 ? 'Potongan kasbon' : null),
                ], $this->finance);
            }
        }

        $installments = app(AssetInstallmentService::class);
        $pickup = Asset::where('name', 'Mobil Pickup L300')->first();

        if ($pickup) {
            $installments->updateAsset($pickup, [
                ...$pickup->only(['name', 'category', 'condition', 'location', 'notes']),
                'purchase_date' => $pickup->purchase_date->toDateString(),
                'value' => $pickup->value,
                'has_installment' => true,
                'total_install' => 144_000_000,
                'installment_amount' => 4_000_000,
                'installment_due_day' => 10,
            ], User::role('LOGISTICS')->firstOrFail());

            $installments->recordPayment($pickup, [
                'amount' => 4_000_000,
                'paid_at' => $lastMonth->copy()->day(10)->toDateString(),
                'bank_account_id' => $bank->id,
                'note' => 'Cicilan leasing',
            ], $this->finance);
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
        $overtimeService->financeDecision($completed, 'approve', $this->finance, null, BankAccount::value('id'));

        $rejected = $overtimeService->create([
            'project_id' => $project->id,
            'hours' => 1.5,
            'rate_per_hour' => 30_000,
            'work_date' => now()->subDays(3)->toDateString(),
            'reason' => 'Lembur tanpa koordinasi PM sebelumnya.',
        ], $this->fieldStaff[2]);
        $overtimeService->pmDecision($rejected, 'reject', $this->pm, 'Tidak ada bukti koordinasi sebelum lembur diajukan.');
    }

    /**
     * Sprint 11 Sub 4–5 through the real services: a Tukang request that
     * went PM → Logistics and became a CUSTOM line (bought, used, the
     * spare handed to the client), one request still waiting at Logistics
     * and one at the PM, and a catalog pair flagged "kemungkinan dobel"
     * after a synonym was added — so the Pengajuan Barang and Cek Duplikat
     * pages have something real.
     */
    private function seedMaterialRequests(Project $project, User $logistics, MaterialCatalogService $catalog): void
    {
        $requests = app(MaterialRequestService::class);
        $projectMaterials = app(ProjectMaterialService::class);
        $tukang = User::find(Task::where('project_id', $project->id)->value('assignee_id')) ?? $this->fieldStaff[0];

        $handle = $requests->submit($project, [
            'name' => 'Handle pintu panjang',
            'qty' => 4,
            'reason' => 'Handle bawaan lemari patah',
        ], $tukang);
        $requests->pmDecide($handle, 'approve', null, $project->pm);
        $requests->review($handle, [
            'decision' => 'CUSTOM',
            'name' => 'Handle pintu aluminium 60cm',
            'spec' => 'Hitam doff',
            'unit_id' => $this->unit('pcs'),
            'qty' => 4,
            'unit_price' => 85_000,
            'vendor_id' => $this->vendor('Toko Besi Sentosa'),
        ], $logistics);
        $projectMaterials->recordPurchase($handle, [
            'qty' => 4,
            'unit_price' => 85_000,
            'purchase_date' => now()->subDays(2)->toDateString(),
        ], $project->pm);
        $projectMaterials->recordUsage($handle, ['qty' => 3], $project->pm);
        $projectMaterials->handOverToClient($handle, ['qty' => 1, 'note' => 'Cadangan disimpan klien'], $project->pm);

        // Still undecided: one at Logistics (from the PM), one at the PM (from the Tukang).
        $requests->submit($project, [
            'name' => 'Kaca cermin bevel 5mm',
            'spec' => '60×90 cm, potong bevel',
            'unit_id' => $this->unit('lbr'),
            'qty' => 2,
            'estimated_price' => 350_000,
            'reason' => 'Ukuran khusus kamar mandi, tidak ada di katalog',
        ], $project->pm);
        $requests->submit($project, ['name' => 'Lem tembak', 'qty' => 2, 'reason' => 'Stok di lokasi habis'], $tukang);

        // Written differently by two suppliers; the synonym added later reveals the duplicate.
        $hardware = MaterialCategory::where('code_prefix', 'HDW')->value('id');
        foreach (['F30' => null, 'F 30' => 'Ditulis berbeda oleh supplier'] as $spec => $reason) {
            $catalog->create([
                'material_category_id' => $hardware,
                'base_name' => 'Paku Tembak',
                'spec' => $spec,
                'unit_id' => $this->unit('dus'),
                'cost_price' => 35_000,
                'sell_price' => 45_000,
                'similar_reason' => $reason,
            ], $logistics);
        }
        MaterialSynonym::firstOrCreate(['term' => 'f 30'], ['canonical' => 'f30']);
        $catalog->rebuildMatchKeys();
    }

    /** Master Satuan id by code — UnitSeeder (run by DatabaseSeeder) provides the common ones. */
    private function unit(string $code): int
    {
        return Unit::firstOrCreate(['code' => $code], ['name' => ucfirst($code)])->id;
    }

    /** Master Vendor id by name, created on first use. */
    private function vendor(string $name): int
    {
        return Vendor::firstOrCreate(['name' => $name], ['type' => Vendor::TYPE_MATERIAL, 'created_by' => $this->ceo->id])->id;
    }
}
