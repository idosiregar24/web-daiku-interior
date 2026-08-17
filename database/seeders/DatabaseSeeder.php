<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * One demo user per PRD §2 role, so every role's view (RBAC-gated
     * sidebar nav, page access) can be checked locally without manually
     * assigning roles by hand. Password for all of these is `password`
     * (see database/factories/UserFactory) — dummy data for local/
     * staging/UAT only (PRD §11.1), never seed this in production.
     */
    private const DEMO_USERS = [
        'CEO' => ['name' => 'CEO Daiku Interior', 'email' => 'ceo@daikuinterior.com'],
        'MARKETING' => ['name' => 'Marketing Daiku Interior', 'email' => 'marketing@daikuinterior.com'],
        'DESIGNER' => ['name' => 'Designer Daiku Interior', 'email' => 'designer@daikuinterior.com'],
        'ESTIMATOR' => ['name' => 'Estimator Daiku Interior', 'email' => 'estimator@daikuinterior.com'],
        'PM' => ['name' => 'PM Daiku Interior', 'email' => 'pm@daikuinterior.com'],
        'QA' => ['name' => 'QA Daiku Interior', 'email' => 'qa@daikuinterior.com'],
        'FINANCE' => ['name' => 'Finance Daiku Interior', 'email' => 'finance@daikuinterior.com'],
        'LOGISTICS' => ['name' => 'Logistics Daiku Interior', 'email' => 'logistics@daikuinterior.com'],
        'FIELD_STAFF' => ['name' => 'Field Staff Daiku Interior', 'email' => 'fieldstaff@daikuinterior.com'],
        'SUPERADMIN' => ['name' => 'Super Admin Daiku Interior', 'email' => 'superadmin@daikuinterior.com'],
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->call(LeadSourceSeeder::class);
        $this->call(MasterDataSeeder::class);

        foreach (self::DEMO_USERS as $role => $attributes) {
            $user = User::factory()->create($attributes);
            $user->assignRole($role);
        }

        // Walks the full presales→execution→payroll business process
        // through the real Service layer (Lead→Design→Quotation→Project→
        // Task→DailyForm→Penalty→Overtime) so there's something real to
        // click through, not just isolated rows — see DemoDataSeeder's
        // own docblock. Same "local/staging/UAT only" caveat as
        // DEMO_USERS above.
        $this->call(DemoDataSeeder::class);
    }
}
