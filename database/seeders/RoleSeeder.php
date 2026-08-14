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
