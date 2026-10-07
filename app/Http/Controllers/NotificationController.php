<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\NotificationService;
use App\Support\NotificationTarget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PRD §7.1 "Notification (own)" row — every role gets R on their own
 * notifications only, enforced here (not a Policy — every query is
 * scoped to the current user, plus a plain ownership check on the
 * per-row actions).
 */
class NotificationController extends Controller
{
    /** PRD §4.9 "Riwayat notifikasi tersimpan 90 hari" — older rows are pruned daily (PruneNotificationsJob). */
    public function index(Request $request): Response
    {
        $notifications = Notification::query()
            ->where('user_id', $request->user()->id)
            ->when($request->boolean('unread'), fn ($query) => $query->where('is_read', false))
            ->where('created_at', '>=', now()->subDays(NotificationService::RETENTION_DAYS))
            ->latest('created_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Notifications/Index', [
            'items' => $notifications,
            'filters' => ['unread' => $request->boolean('unread')],
        ]);
    }

    /**
     * Sprint 18 — a GET so a device push can open it straight from the
     * service worker. Marking one's own notification read is the only side
     * effect, and the redirect target always comes from route(), never from
     * the request, so it can't be turned into an open redirect.
     */
    public function open(Request $request, Notification $notification, NotificationService $service): RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        $service->markAsRead($notification);

        return redirect()->to(NotificationTarget::for($notification));
    }

    public function markAsRead(Request $request, Notification $notification, NotificationService $service): RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        $service->markAsRead($notification);

        return back();
    }

    public function markAllAsRead(Request $request, NotificationService $service): RedirectResponse
    {
        $service->markAllAsRead($request->user());

        return back()->with('success', 'Semua notifikasi ditandai sudah dibaca.');
    }
}
