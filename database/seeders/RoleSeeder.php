<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * The nine stakeholder roles from PRD section 2 (Stakeholders & Users).
     * Permissions per module are defined later against the RBAC matrix in
     * PRD section 7.1 as each module's controllers/policies are built.
     *
     * `SUPERADMIN` is NOT part of the PRD — it's a technical admin role
     * added on top, with unconditional access to everything (see
     * app/Http/Middleware/RoleMiddleware.php) plus CRUD over Master Data
     * (Branches, Lead Sources, Lead Categories — app/Http/Controllers/MasterData).
     * It exists for system administration, separate from CEO's business role.
     */
    private const ROLES = [
        'CEO',
        'MARKETING',
        'DESIGNER',
        'ESTIMATOR',
        'PM',
        'QA',
        'FINANCE',
        'LOGISTICS',
        'FIELD_STAFF',
        'SUPERADMIN',
    ];

    /**
     * Seed the application's roles.
     */
    public function run(): void
    {
        foreach (self::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }
    }
}
