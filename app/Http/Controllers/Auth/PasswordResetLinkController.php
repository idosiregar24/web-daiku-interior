<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /** The same answer whether or not the email belongs to an account (Sprint 21 Sub 05). */
    public const SENT = 'Jika email tersebut terdaftar, link atur ulang password sudah dikirim ke sana. Akun tanpa email? Minta CEO mengatur ulang password Anda.';

    /**
     * Display the password reset link request view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming password reset link request. The outcome
     * (sent / no such email / throttled) is never revealed — the form
     * must not tell an outsider which emails have an account.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ], [
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
        ]);

        Password::sendResetLink(['email' => mb_strtolower(trim($request->string('email')->value()))]);

        return back()->with('status', self::SENT);
    }
}
