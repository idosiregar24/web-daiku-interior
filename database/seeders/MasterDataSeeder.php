<?php

namespace Database\Seeders;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\LeadCategory;
use Illuminate\Database\Seeder;

/**
 * Reference/lookup tables for the SuperAdmin-editable Data Master module
 * (.claude/plan/README.md "SUPERADMIN role + Data Master module") — kept
 * separate from LeadSourceSeeder since that one already existed and is
 * called on its own.
 */
class MasterDataSeeder extends Seeder
{
    private const BRANCHES = [
        ['name' => 'Daiku Interior Pusat', 'code' => 'PST', 'address' => 'Jl. Raya Bogor No. 1, Jakarta Timur'],
        ['name' => 'Daiku Interior Bandung', 'code' => 'BDG', 'address' => 'Jl. Dago No. 45, Bandung'],
    ];

    // Matches StoreLeadRequest/UpdateLeadRequest's Rule::in() list exactly.
    private const LEAD_CATEGORIES = ['RESIDENTIAL', 'KOMERSIAL', 'DEVELOPER', 'KONTRAKTOR', 'LAINNYA'];

    private const BANK_ACCOUNTS = [
        ['bank_name' => 'BCA', 'account_no' => '5835123456', 'label' => 'BCA 5835', 'balance' => 250_000_000, 'is_active' => true],
        ['bank_name' => 'Mandiri', 'account_no' => '1300009988', 'label' => 'Mandiri Operasional', 'balance' => 120_000_000, 'is_active' => true],
        ['bank_name' => 'BRI', 'account_no' => '0092012345', 'label' => 'BRI Cadangan', 'balance' => 45_000_000, 'is_active' => true],
    ];

    public function run(): void
    {
        foreach (self::BRANCHES as $branch) {
            Branch::firstOrCreate(['code' => $branch['code']], $branch);
        }

        foreach (self::LEAD_CATEGORIES as $name) {
            LeadCategory::firstOrCreate(['name' => $name]);
        }

        foreach (self::BANK_ACCOUNTS as $account) {
            BankAccount::firstOrCreate(['label' => $account['label']], $account);
        }
    }
}
