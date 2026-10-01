<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope is a dev-only dependency (composer.json require-dev):
        // after `composer install --no-dev` its base class doesn't exist, so
        // the provider can't be listed unconditionally in bootstrap/providers.php.
        if (class_exists(TelescopeApplicationServiceProvider::class)) {
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // PRD §9.5 "HTTPS wajib": production sits behind nginx TLS
        // (docker/nginx/production.conf), so every generated URL/redirect
        // must be https even if a request reached PHP marked as http.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // SUPERADMIN god-mode (database/seeders/RoleSeeder.php) covers
        // `role:` route middleware via app/Http/Middleware/RoleMiddleware.php
        // — Policies (first one: TaskPolicy) are a separate authorization
        // layer that middleware doesn't touch, so it needs its own bypass
        // here or SUPERADMIN would get blocked by ownership checks meant
        // for regular roles.
        Gate::before(fn ($user) => $user->hasRole('SUPERADMIN') ? true : null);
    }
}
