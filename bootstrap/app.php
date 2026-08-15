<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RoleMiddleware as AppRoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Spatie Laravel Permission — required for `role:`, `permission:` and
        // `role_or_permission:` route middleware used throughout PRD §7.1's
        // RBAC matrix (see .claude/rules/backend-standards.md §3). Laravel 11
        // no longer auto-registers package middleware aliases via a Kernel,
        // so this has to be explicit or `Route::middleware('role:CEO')`
        // fails with "Target class [role] does not exist".
        //
        // `role` points at our own AppRoleMiddleware (wraps Spatie's), not
        // Spatie's directly — it adds a SUPERADMIN bypass so that technical
        // admin role gets unconditional access to every role-gated route.
        $middleware->alias([
            'role' => AppRoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
