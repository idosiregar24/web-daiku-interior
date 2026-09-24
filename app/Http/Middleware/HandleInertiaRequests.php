<?php

namespace App\Http\Middleware;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
            // Every controller redirects with `->with('success', ...)` —
            // AppLayout turns these into toasts. `key` is unique per flash:
            // Inertia keeps un-requested props across partial reloads, so
            // the client toasts once per key, not once per render.
            'flash' => function () use ($request) {
                $success = $request->session()->get('success');
                $error = $request->session()->get('error');

                return $success || $error
                    ? ['success' => $success, 'error' => $error, 'key' => (string) Str::uuid()]
                    : null;
            },
            // Refreshed on every Inertia visit, and live between visits:
            // AppLayout's bell partial-reloads just these two props when
            // a NotificationCreated event lands on the user's Echo channel.
            'notifications' => $user
                ? Notification::where('user_id', $user->id)->where('is_read', false)->latest('created_at')->limit(10)->get()
                : [],
            'unreadNotificationsCount' => $user
                ? Notification::where('user_id', $user->id)->where('is_read', false)->count()
                : 0,
        ];
    }
}
