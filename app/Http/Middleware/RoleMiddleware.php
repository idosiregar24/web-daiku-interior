<?php

namespace App\Http\Middleware;

use Closure;
use Spatie\Permission\Middleware\RoleMiddleware as BaseRoleMiddleware;

/**
 * Wraps Spatie's `role:` middleware with a SUPERADMIN bypass — SUPERADMIN
 * is a technical admin role (not part of PRD §7.1's business RBAC matrix)
 * that gets unconditional access to every `role:`-gated route, without
 * having to list it on every single route or juggle multiple roles per
 * user. See database/seeders/RoleSeeder.php for why this role exists.
 *
 * Registered as the `role` alias in bootstrap/app.php in place of
 * Spatie's own RoleMiddleware. Signature must match the parent's exactly
 * (PHP enforces LSP-compatible overrides even on untyped params).
 */
class RoleMiddleware extends BaseRoleMiddleware
{
    public function handle($request, Closure $next, $role, $guard = null)
    {
        if ($request->user()?->hasRole('SUPERADMIN')) {
            return $next($request);
        }

        return parent::handle($request, $next, $role, $guard);
    }
}
