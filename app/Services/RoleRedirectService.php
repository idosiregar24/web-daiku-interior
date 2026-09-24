<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Route;

class RoleRedirectService
{
    /**
     * Route name each role should land on immediately after login, per PRD
     * §7 (RBAC) and §8.4 ("Layout per Role" — e.g. Field Staff gets a
     * simplified task-list-first view, Finance gets cash flow + termin
     * calendar, PM gets the milestone/task board).
     *
     * Roles whose PRD §8.4 home page exists land there; the rest keep the
     * shared `dashboard` (PM's "task board per proyek" lives inside each
     * project, not at one URL). Update the mapping as modules land
     * instead of hardcoding redirects elsewhere. `routeNameFor()` falls
     * back to `dashboard` automatically if a mapped route name isn't
     * registered, so this file is safe to update ahead of its route.
     */
    private const ROLE_ROUTES = [
        'CEO' => 'analytics.index',            // "Dashboard utama"
        'MARKETING' => 'crm.dashboard',
        'DESIGNER' => 'dashboard',
        'ESTIMATOR' => 'dashboard',
        'PM' => 'dashboard',
        'QA' => 'dashboard',
        'FINANCE' => 'finance.dashboard',      // "Dashboard cash flow + termin"
        'LOGISTICS' => 'logistics.materials.index',
        'FIELD_STAFF' => 'tasks.index',        // "hanya task list & form daily"
        // SUPERADMIN is a technical role (RoleSeeder), not a PRD §7.1
        // business role — lands straight on its own tool instead of the
        // business dashboard.
        'SUPERADMIN' => 'master-data.index',
    ];

    /**
     * Resolve the route name a given user should be redirected to after
     * authenticating, based on their primary Spatie role.
     */
    public function routeNameFor(User $user): string
    {
        $role = $user->getRoleNames()->first();
        $routeName = self::ROLE_ROUTES[$role] ?? 'dashboard';

        return Route::has($routeName) ? $routeName : 'dashboard';
    }
}
