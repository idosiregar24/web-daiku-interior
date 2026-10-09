<?php

use App\Http\Middleware\EnsureLinkedEmployee;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\ForgetActionInbox;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ModuleAccessMiddleware;
use App\Http\Middleware\NoIndexSystemPages;
use App\Http\Middleware\RoleMiddleware as AppRoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        // Sprint 20 — the public company profile pages, without a session.
        then: function () {
            Route::middleware('site')->group(base_path('routes/site.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // Sprint 13 — drops the user's cached "Perlu Tindakan" counts after each write.
            ForgetActionInbox::class,
            // Sprint 21 Sub 05 — after a CEO password reset, only "Buat Password Baru" opens.
            EnsurePasswordChanged::class,
            // Sprint 20 Sub 03 — the system's pages never show up in Google.
            NoIndexSystemPages::class,
        ]);

        // Sprint 20 — routes/site.php: public, read-only, cacheable pages.
        // No session/cookies on purpose; `/` stays in `web` (staff redirect).
        $middleware->group('site', [
            SubstituteBindings::class,
            'cache.headers:public;max_age=300;etag',
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
        // `module` gates a whole module route group (Sprint 10 decision #2 —
        // swapped for per-user module access later); `employee.self` gates
        // the SDM "Milik Saya" pages to users linked to an employee row.
        $middleware->alias([
            'module' => ModuleAccessMiddleware::class,
            'employee.self' => EnsureLinkedEmployee::class,
            'role' => AppRoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
