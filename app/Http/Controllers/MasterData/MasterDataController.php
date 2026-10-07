<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\City;
use App\Models\LeadCategory;
use App\Models\LeadSource;
use App\Models\MaterialCategory;
use App\Models\MaterialSynonym;
use App\Models\Unit;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SuperAdmin-only reference-data screen (not part of PRD §7.1 — added on
 * request, see database/seeders/RoleSeeder.php). One page, tabbed by
 * entity; mutations go through the per-entity controllers in this same
 * namespace (BranchController, LeadSourceController, LeadCategoryController,
 * BankAccountController).
 */
class MasterDataController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('MasterData/Index', [
            'branches' => Branch::query()->orderBy('name')->get(),
            'leadSources' => LeadSource::query()->orderBy('name')->get(),
            'leadCategories' => LeadCategory::query()->orderBy('name')->get(),
            // Sprint 16 Sub 08 — Master Kota; `leads_count` > 0 → rename only, no delete.
            'cities' => City::query()->ordered()->withCount('leads')->get(),
            // Saldo Saat Ini is derived (opening_balance + transactions).
            'bankAccounts' => BankAccount::query()->withBalance()->orderBy('bank_name')->get()->append('current_balance'),
            // `in_use` decides delete vs. deactivate-only (UnitController::destroy()).
            'units' => Unit::query()
                ->ordered()
                ->withExists(['materials as has_materials', 'quotationItems as has_quotation_items', 'projectMaterials as has_project_materials'])
                ->get()
                ->map(fn (Unit $unit) => [
                    ...$unit->only(['id', 'code', 'name', 'is_active', 'sort_order']),
                    'in_use' => $unit->has_materials || $unit->has_quotation_items || $unit->has_project_materials,
                ]),
            // Sprint 11 Sub 5 — categories in use can only be deactivated.
            'materialCategories' => MaterialCategory::query()->ordered()->withCount('materials')->get(),
            'materialSynonyms' => MaterialSynonym::query()->orderBy('canonical')->orderBy('term')->get(),
        ]);
    }
}
