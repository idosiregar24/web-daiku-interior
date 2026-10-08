<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\RoleRedirectService;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    /**
     * Sprint 21 Sub 05 — "Buat Password Baru" after the CEO reset the
     * password (EnsurePasswordChanged sends the user here).
     */
    public function edit(Request $request, RoleRedirectService $roleRedirect): Response|RedirectResponse
    {
        if (! $request->user()->must_change_password) {
            return redirect()->route($roleRedirect->routeNameFor($request->user()));
        }

        return Inertia::render('Auth/ChangePassword', [
            'account' => $request->user()->loginLabel(),
        ]);
    }

    /**
     * Update the user's password. After a CEO reset the user has just
     * signed in with that temporary password, so it isn't asked again.
     */
    public function update(Request $request, UserService $service, RoleRedirectService $roleRedirect): RedirectResponse
    {
        $forced = (bool) $request->user()->must_change_password;

        $validated = $request->validate([
            'current_password' => $forced ? ['nullable'] : ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ], [
            'current_password.required' => 'Password saat ini wajib diisi.',
            'current_password.current_password' => 'Password saat ini salah.',
            'password.required' => 'Password baru wajib diisi.',
            'password.confirmed' => 'Konfirmasi password tidak sama.',
        ]);

        $service->changeOwnPassword($request->user(), $validated['password']);

        if ($forced) {
            return redirect()->route($roleRedirect->routeNameFor($request->user()))
                ->with('success', 'Password baru tersimpan.');
        }

        return back();
    }
}
