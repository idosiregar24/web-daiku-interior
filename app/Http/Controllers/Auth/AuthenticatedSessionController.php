<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\RoleRedirectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request') && $this->mailerDeliversEmail(),
            'status' => session('status'),
        ]);
    }

    /**
     * The `log`/`array` mailers never deliver the reset link, so the
     * "Lupa password?" link would be a dead end — only show it once a real
     * mailer (smtp, ses, …) is configured. The reset routes stay registered
     * either way.
     */
    private function mailerDeliversEmail(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request, RoleRedirectService $roleRedirect): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $routeName = $roleRedirect->routeNameFor($request->user());

        return redirect()->intended(route($routeName, absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        // Sprint 20 — `/` is the public company profile (Blade); staff who
        // sign out go back to the login, which is also an Inertia page.
        return redirect()->route('login');
    }
}
