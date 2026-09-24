<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PRD §7.1 "Notification (own)" row — every role gets R on their own
 * notifications only, enforced here (not a Policy — every query is
 * scoped to the current user, plus a plain ownership check on the one
 * per-row action).
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

    public function markAsRead(Request $request, Notification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        $notification->update(['is_read' => true]);

        return back();
    }

    public function markAllAsRead(Request $request): RedirectResponse
    {
        Notification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return back()->with('success', 'Semua notifikasi ditandai sudah dibaca.');
    }
}
