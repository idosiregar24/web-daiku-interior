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
        ['devices' => $devices, 'delivered' => $delivered, 'failure' => $failure] = $service->sendTest($request->user());

        if (! WebPushService::enabled()) {
            return back()->with('error', 'Notifikasi perangkat belum dikonfigurasi di server (VAPID).');
        }

        if ($devices === 0) {
            return back()->with('error', 'Belum ada perangkat yang mengaktifkan notifikasi.');
        }

        if ($delivered === 0) {
            // 403 = the push service refused our VAPID identity (key pair, subject, clock or a
            // device subscribed with an older key) — `daiku:push-check` tells which.
            return back()->with('error', 'Notifikasi uji gagal terkirim'.($failure ? " ({$failure})" : '').' — matikan lalu aktifkan lagi notifikasi di perangkat ini.'
                .(str_starts_with((string) $failure, '403') ? ' Bila tetap gagal, admin server menjalankan php artisan daiku:push-check.' : ''));
        }

        // Some devices took it, some didn't (an old phone, a revoked browser…).
        return back()->with('success', "Notifikasi uji dikirim ke {$delivered} dari {$devices} perangkat.".($failure && $delivered < $devices ? " Gagal: {$failure}." : ''));
    }
}
