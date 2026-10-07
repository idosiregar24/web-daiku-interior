<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\DestroyPushSubscriptionRequest;
use App\Http\Requests\Profile\StorePushSubscriptionRequest;
use App\Models\PushSubscription;
use App\Services\PushSubscriptionService;
use App\Services\WebPushService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sprint 18 Sub 04 — every role, own devices only (like "Notification
 * (own)" in PRD §7.1): no role gate, the rows are always scoped to — or
 * checked against — the current user.
 */
class PushSubscriptionController extends Controller
{
    public function store(StorePushSubscriptionRequest $request, PushSubscriptionService $service): RedirectResponse
    {
        $service->subscribe($request->user(), $request->validated(), $request->userAgent());

        return back();
    }

    /** "Matikan di perangkat ini". */
    public function unsubscribe(DestroyPushSubscriptionRequest $request, PushSubscriptionService $service): RedirectResponse
    {
        $service->unsubscribe($request->user(), $request->validated('endpoint'));

        return back();
    }

    /** "Hapus perangkat" from the device list (Pengaturan Notifikasi). */
    public function destroy(Request $request, PushSubscription $pushSubscription): RedirectResponse
    {
        abort_unless($pushSubscription->user_id === $request->user()->id, 403);

        $pushSubscription->delete();

        return back()->with('success', 'Perangkat dihapus — tidak akan menerima notifikasi lagi.');
    }

    public function test(Request $request, WebPushService $service): RedirectResponse
    {
        ['devices' => $devices, 'delivered' => $delivered] = $service->sendTest($request->user());

        if (! WebPushService::enabled()) {
            return back()->with('error', 'Notifikasi perangkat belum dikonfigurasi di server (VAPID).');
        }

        return $delivered > 0
            ? back()->with('success', "Notifikasi uji dikirim ke {$delivered} perangkat.")
            : back()->with('error', $devices > 0
                ? 'Notifikasi uji gagal terkirim — coba matikan lalu aktifkan lagi di perangkat ini.'
                : 'Belum ada perangkat yang mengaktifkan notifikasi.');
    }
}
