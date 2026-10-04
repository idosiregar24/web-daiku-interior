<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `module:{key}` — one gate per module route group (Sprint 10 decision #2).
 * Today a module is open to a fixed set of roles; the planned per-user
 * module access (SUPERADMIN decides which modules each user may open)
 * only has to change this class, not every route of the module.
 * Finer per-action rules stay on the routes (`role:`) and in Policies.
 * SUPERADMIN passes every module, like the `role:` bypass.
 */
class ModuleAccessMiddleware
{
    /** @var array<string, list<string>> module key → roles allowed in */
    public const MODULE_ROLES = [
        'hr' => ['CEO', 'HR'],
    ];

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();

        abort_unless($user && array_key_exists($module, self::MODULE_ROLES), 403);

        if (! $user->hasRole('SUPERADMIN') && ! $user->hasAnyRole(self::MODULE_ROLES[$module])) {
            abort(403);
        }

        return $next($request);
    }
}
