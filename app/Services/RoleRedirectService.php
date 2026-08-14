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
     * Every entry below currently resolves to `dashboard` because none of
     * those role-specific landing pages exist yet — update the mapping as
     * each module lands (see .claude/plan) instead of hardcoding redirects
     * elsewhere. `routeNameFor()` falls back to `dashboard` automatically
     * if a mapped route name isn't registered yet, so this file is safe to
     * update ahead of the route/controller that backs it.
     */
    private const ROLE_ROUTES = [
        'CEO' => 'dashboard',
        'MARKETING' => 'dashboard',
        'DESIGNER' => 'dashboard',
        'ESTIMATOR' => 'dashboard',
        'PM' => 'dashboard',
        'QA' => 'dashboard',
        'FINANCE' => 'dashboard',
        'LOGISTICS' => 'dashboard',
        'FIELD_STAFF' => 'dashboard',
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
