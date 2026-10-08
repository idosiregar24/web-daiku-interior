<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ConfirmablePasswordController extends Controller
{
    /**
     * Show the confirm password view.
     */
    public function show(): Response
    {
        return Inertia::render('Auth/ConfirmPassword');
    }

    /**
     * Confirm the user's password — checked against the signed-in account
     * itself, not looked up by email: since Sprint 21 an account may have
     * only a username.
     */
    public function store(Request $request): RedirectResponse
    {
        if (! Hash::check((string) $request->input('password'), $request->user()->getAuthPassword())) {
            throw ValidationException::withMessages([
                'password' => 'Password salah.',
            ]);
        }

        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
