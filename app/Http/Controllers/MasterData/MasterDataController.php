<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\LeadCategory;
use App\Models\LeadSource;
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
            'bankAccounts' => BankAccount::query()->orderBy('bank_name')->get(),
        ]);
    }
}
