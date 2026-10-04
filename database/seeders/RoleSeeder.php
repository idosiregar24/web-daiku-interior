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
     *
     * `HR` (SDM) is also outside the PRD — added in Sprint 10 for the SDM
     * module (employees, discipline, salary changes, KPI, reviews; see
     * .claude/plan/sprint-10-sdm.md decision #2).
     *
     * Sprint 12 (revisi alur, outside the PRD) adds `ASISTEN_PM` (decision
     * #22 — ACC RAB and material requests, never budget allocation or
     * realisation) and `KEPALA_DESAIN` (decision #15 — stacked on top of
     * DESIGNER, see User::STACKED_ROLES).
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
        'HR',
        'ASISTEN_PM',
        'KEPALA_DESAIN',
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
