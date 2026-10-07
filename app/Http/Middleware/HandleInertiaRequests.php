<?php

namespace App\Http\Middleware;

use App\Enums\NotificationPriority;
use App\Enums\NotificationType;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\ProjectOpening;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\ActionInboxService;
use App\Services\WebPushService;
use Illuminate\Database\Eloquent\Collection;
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
                    // Primary role per PRD 7 (RBAC) — what nav gating and role
                    // checks read. A stacked role (Kepala Desain, Sprint 12) is
                    // never primary: its base role (DESIGNER) is.
                    'role' => $user->primaryRoleName(),
                    // Every role held — nav shows a menu if any of them may see it.
                    'roles' => $user->getRoleNames()->values(),
                    // The role to label the user with (Kepala Desain over Desainer).
                    'display_role' => $user->assignableRoleName(),
                    // SDM "Milik Saya" menu — same rule as the `employee.self`
                    // middleware (field staff are never linked, decision #11).
                    'has_employee' => ! $user->hasRole('FIELD_STAFF')
                        && Employee::query()->hrEligible()->active()->where('user_id', $user->id)->exists(),
                    // Sprint 13 Sub 01 — sidebar groups this user folded.
                    'nav_preferences' => [
                        'collapsed_groups' => $user->nav_preferences['collapsed_groups'] ?? [],
                    ],
                    // Sprint 18 Sub 05 — the live toast's chime follows these too.
                    'notification_preferences' => [
                        'muted_categories' => $user->mutedNotificationCategories(),
                        'sound' => $user->wantsNotificationSound(),
                    ],
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
            // Web customization (Pengaturan Situs) — name, tagline, logo,
            // favicon, login-page image/headline. Shared with guests too:
            // the login page is branded.
            'site' => fn () => SiteSetting::current()->branding(),
            // Refreshed on every Inertia visit, and live between visits:
            // AppLayout's bell partial-reloads these (+ navBadges) when a
            // NotificationCreated event lands on the user's Echo channel,
            // or every 60 s while the socket is down. Unread "klien
            // menunggu" (P1) first, so an older one is never pushed out.
            'notifications' => $user ? $this->unreadNotifications($user) : [],
            // Sprint 12 #19 — the CEO's "Buka Proyek" pop-up, on every page.
            'pendingProjectOpenings' => fn () => $user?->hasAnyRole(['CEO', 'SUPERADMIN'])
                ? $this->pendingOpenings()
                : null,
            // Sprint 13 #5 — menu route => items waiting there ("Perlu
            // Tindakan" counts, cached 60 s per user — ActionInboxService).
            'navBadges' => fn () => $user ? app(ActionInboxService::class)->badges($user) : [],
            'unreadNotificationsCount' => $user
                ? Notification::where('user_id', $user->id)->where('is_read', false)->count()
                : 0,
            // Sprint 18 Sub 04 — the VAPID *public* key the browser needs to
            // subscribe (null = Web Push not configured on this server).
            'webPushKey' => fn () => $user && WebPushService::enabled() ? config('services.webpush.public_key') : null,
            // Sprint 17 Sub 01 (K1) — client links are built from APP_URL; a
            // local host can't be opened from a client's phone, so the share
            // panel warns about it. Decided here, never guessed in the browser.
            'appUrlIsLocal' => fn () => self::appUrlIsLocal(),
        ];
    }

    /** True when APP_URL's host is only reachable from this machine/LAN dev setup. */
    public static function appUrlIsLocal(): bool
    {
        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return $host === ''
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.local')
            || str_starts_with($host, '127.')
            || $host === '[::1]'
            || $host === '::1';
    }

    /** @return Collection<int, Notification> */
    private function unreadNotifications(User $user): Collection
    {
        $clientWaiting = NotificationType::storedValuesOf(NotificationPriority::ClientWaiting);
        $placeholders = implode(',', array_fill(0, count($clientWaiting), '?'));

        return Notification::where('user_id', $user->id)
            ->where('is_read', false)
            ->orderByRaw("CASE WHEN type IN ({$placeholders}) THEN 0 ELSE 1 END", $clientWaiting)
            ->latest('created_at')
            ->latest('id')
            ->limit(10)
            ->get();
    }

    /** @return array{openings: mixed, projectManagers: mixed, assistantPms: mixed}|null */
    private function pendingOpenings(): ?array
    {
        $openings = ProjectOpening::query()
            ->waiting()
            ->with(['lead:id,client_name', 'quotation:id,total_amount,version,client_approved_at'])
            ->oldest()
            ->get();

        return $openings->isEmpty() ? null : [
            'openings' => $openings,
            'projectManagers' => User::role('PM')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'assistantPms' => User::role('ASISTEN_PM')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }
}
