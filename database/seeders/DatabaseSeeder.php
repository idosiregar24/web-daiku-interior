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
        'CEO' => ['name' => 'CEO Daiku Interior', 'username' => 'ceo', 'email' => 'ceo@daikuinterior.com'],
        'MARKETING' => ['name' => 'Marketing Daiku Interior', 'username' => 'marketing', 'email' => 'marketing@daikuinterior.com'],
        'DESIGNER' => ['name' => 'Designer Daiku Interior', 'username' => 'designer', 'email' => 'designer@daikuinterior.com'],
        'ESTIMATOR' => ['name' => 'Estimator Daiku Interior', 'username' => 'estimator', 'email' => 'estimator@daikuinterior.com'],
        'PM' => ['name' => 'PM Daiku Interior', 'username' => 'pm', 'email' => 'pm@daikuinterior.com'],
        'QA' => ['name' => 'QA Daiku Interior', 'username' => 'qa', 'email' => 'qa@daikuinterior.com'],
        'FINANCE' => ['name' => 'Finance Daiku Interior', 'username' => 'finance', 'email' => 'finance@daikuinterior.com'],
        'LOGISTICS' => ['name' => 'Logistics Daiku Interior', 'username' => 'logistics', 'email' => 'logistics@daikuinterior.com'],
        'FIELD_STAFF' => ['name' => 'Field Staff Daiku Interior', 'username' => 'fieldstaff', 'email' => 'fieldstaff@daikuinterior.com'],
        'SUPERADMIN' => ['name' => 'Super Admin Daiku Interior', 'username' => 'superadmin', 'email' => 'superadmin@daikuinterior.com'],
        'HR' => ['name' => 'HR Daiku Interior', 'username' => 'hr', 'email' => 'hr@daikuinterior.com'],
        // Sprint 12 — Kepala Desain is stacked on DESIGNER (User::rolesFor()).
        'ASISTEN_PM' => ['name' => 'Asisten PM Daiku Interior', 'username' => 'asistenpm', 'email' => 'asistenpm@daikuinterior.com'],
        'KEPALA_DESAIN' => ['name' => 'Kepala Desain Daiku Interior', 'username' => 'kepaladesain', 'email' => 'kepaladesain@daikuinterior.com'],
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Demo users all share the password `password` — never let a
        // stray `db:seed` in production create them.
        if (app()->isProduction()) {
            $this->command?->warn('APP_ENV=production: menjalankan ProductionSeeder, bukan data demo.');
            $this->call(ProductionSeeder::class);

            return;
        }

        $this->call(RoleSeeder::class);
        $this->call(LeadSourceSeeder::class);
        $this->call(CitySeeder::class);
        $this->call(UnitSeeder::class);
        $this->call(MaterialCatalogSeeder::class);
        $this->call(MasterDataSeeder::class);
        $this->call(FinanceAllocationConfigSeeder::class);
        // Sprint 15 — the company profile on the letterhead (PDF & client link).
        $this->call(SiteSettingSeeder::class);
        // Sprint 20 — the company profile's service pages (unpublished placeholders).
        $this->call(ServicePageSeeder::class);

        foreach (self::DEMO_USERS as $role => $attributes) {
            $user = User::factory()->create($attributes);
            $user->assignRole(User::rolesFor($role));
        }

        // Sprint 21 — a Tukang with a username and no email: logs in as
        // `budi` / `password`; "lupa password" goes through the CEO.
        User::factory()->create(['name' => 'Budi Tukang', 'username' => 'budi', 'email' => null, 'email_verified_at' => null])
            ->assignRole('FIELD_STAFF');

        // Walks the full presales→execution→payroll business process
        // through the real Service layer (Lead→Design→Quotation→Project→
        // Task→DailyForm→Penalty→Overtime) so there's something real to
        // click through, not just isolated rows — see DemoDataSeeder's
        // own docblock. Same "local/staging/UAT only" caveat as
        // DEMO_USERS above.
        $this->call(DemoDataSeeder::class);

        // Every remaining branch of the Sprint 12 flow (surveys, RAB stops,
        // rejected invoices, design revisions, projects on hold / cancelled /
        // completed, overdue items, overruns, …) — one example each.
        $this->call(Demo\WorkflowScenarioSeeder::class);

        // Sprint 13 — the demo Tukang's "Hari Ini": tasks due today, some
        // forms still missing (every other role's "Perlu Tindakan" queue is
        // already filled by the two seeders above).
        $this->call(Demo\TodayDemoSeeder::class);

        // SDM (Sprint 10) on top of DemoDataSeeder's employees, through the
        // real services: warnings + salary changes, KPI templates and three
        // months of scores, then semester reviews (which read the closed
        // KPI months — hence last).
        $this->call([
            Demo\DisciplineSalaryDemoSeeder::class,
            Demo\KpiDemoSeeder::class,
            Demo\PerformanceReviewDemoSeeder::class,
        ]);
    }
}
