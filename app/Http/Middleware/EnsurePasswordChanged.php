<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sprint 21 Sub 05 — a password the CEO set (Edit User) is temporary.
 * Until the user makes their own, the only pages open to them are "Buat
 * Password Baru" and logout.
 */
class EnsurePasswordChanged
{
    private const ALLOWED = ['password.change', 'password.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->must_change_password || $request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(403, 'Buat password baru terlebih dahulu.');
        }

        return redirect()->route('password.change');
    }
}
