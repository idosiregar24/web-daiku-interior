<?php

namespace Database\Seeders;

use App\Models\LeadCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Production bootstrap (CSV Sprint 7 "Seed data production-ready"):
 *
 *   php artisan db:seed --class=ProductionSeeder --force
 *
 * Seeds only real reference data — roles, lead sources/categories — and
 * the first two accounts, whose credentials come from the environment
 * (never a hardcoded password). No demo users, no demo bank accounts or
 * branches: those are entered through Data Master (SUPERADMIN) with the
 * company's real details. Idempotent — safe to re-run on deploy.
 */
class ProductionSeeder extends Seeder
{
    /** Matches StoreLeadRequest/UpdateLeadRequest's Rule::in() list. */
    private const LEAD_CATEGORIES = ['RESIDENTIAL', 'KOMERSIAL', 'DEVELOPER', 'KONTRAKTOR', 'LAINNYA'];

    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->call(LeadSourceSeeder::class);

        foreach (self::LEAD_CATEGORIES as $name) {
            LeadCategory::firstOrCreate(['name' => $name]);
        }

        $this->bootstrapAccount('CEO', 'INITIAL_CEO');
        $this->bootstrapAccount('SUPERADMIN', 'INITIAL_SUPERADMIN');
    }

    /**
     * Created once from `{PREFIX}_NAME/_EMAIL/_PASSWORD`; an existing
     * account is left untouched so a re-run never resets a password.
     */
    private function bootstrapAccount(string $role, string $prefix): void
    {
        $email = env("{$prefix}_EMAIL");
        $password = env("{$prefix}_PASSWORD");

        if (! $email || ! $password) {
            throw new RuntimeException("Set {$prefix}_EMAIL dan {$prefix}_PASSWORD di .env sebelum menjalankan ProductionSeeder.");
        }

        if (strlen($password) < 12) {
            throw new RuntimeException("{$prefix}_PASSWORD minimal 12 karakter.");
        }

        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => env("{$prefix}_NAME", $role === 'CEO' ? 'CEO Daiku Interior' : 'Administrator Sistem'), 'password' => Hash::make($password)],
        );

        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }
    }
}
