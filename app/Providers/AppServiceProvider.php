<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // SUPERADMIN god-mode (database/seeders/RoleSeeder.php) covers
        // `role:` route middleware via app/Http/Middleware/RoleMiddleware.php
        // — Policies (first one: TaskPolicy) are a separate authorization
        // layer that middleware doesn't touch, so it needs its own bypass
        // here or SUPERADMIN would get blocked by ownership checks meant
        // for regular roles.
        Gate::before(fn ($user) => $user->hasRole('SUPERADMIN') ? true : null);
    }
}
