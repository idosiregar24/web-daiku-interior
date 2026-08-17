<?php

namespace App\Http\Middleware;

use App\Models\Notification;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    ...$user->only(['id', 'name', 'email', 'email_verified_at', 'is_active']),
                    // Single primary role per PRD 7 (RBAC) — a user may hold more
                    // than one Spatie role, but the UI only needs the first for
                    // nav gating today.
                    'role' => $user->getRoleNames()->first(),
                ] : null,
            ],
            // Not real-time (see notifications migration's docblock) —
            // refreshes on every Inertia navigation, which is enough for
            // the two Sprint 4 triggers that write here (QA rejection,
            // Termin H-3 reminder) without building the full Echo/Soketi
            // broadcast layer ahead of its own Sprint 5 module.
            'notifications' => $user
                ? Notification::where('user_id', $user->id)->where('is_read', false)->latest('created_at')->limit(10)->get()
                : [],
        ];
    }
}
