<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * PRD §7.1 "Notification (own)" row — every role gets R on their own
 * notifications only, enforced here (not a Policy — the only action is
 * "mark read", scoped by a plain ownership check like Field Staff's task
 * scoping elsewhere).
 */
class NotificationController extends Controller
{
    public function markAsRead(Request $request, Notification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        $notification->update(['is_read' => true]);

        return back();
    }
}
