<?php

namespace App\Http\Controllers\Profile;

use App\Enums\NotificationCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateNotificationPreferenceRequest;
use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 18 Sub 05 — Pengaturan Notifikasi: which categories may ring this
 * user's devices, the in-app chime, and the devices themselves. Own account
 * only (no route parameter), every role.
 */
class NotificationPreferenceController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Notifications', [
            'categories' => array_map(fn (NotificationCategory $category) => [
                'value' => $category->value,
                'label' => $category->label(),
                'client_waiting' => $category->hasClientWaiting(),
            ], $this->categoriesFor($user)),
            'preferences' => [
                'muted_categories' => $user->mutedNotificationCategories(),
                'sound' => $user->wantsNotificationSound(),
            ],
            'devices' => $user->pushSubscriptions()
                ->latest('last_used_at')
                ->latest('id')
                ->get()
                ->map(fn (PushSubscription $device) => [
                    'id' => $device->id,
                    'user_agent' => $device->user_agent,
                    'last_used_at' => $device->last_used_at?->toIso8601String(),
                    'created_at' => $device->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function update(UpdateNotificationPreferenceRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill([
            'notification_preferences' => [
                'muted_categories' => array_values($request->validated('muted_categories')),
                'sound' => $request->boolean('sound'),
            ],
        ])->save();

        return back()->with('success', 'Pengaturan notifikasi disimpan.');
    }

    /**
     * The role mapping, plus any category this user actually received in the
     * retention window — so a toggle is never missing for something that
     * really rings them.
     *
     * @return list<NotificationCategory>
     */
    private function categoriesFor(User $user): array
    {
        $received = Notification::where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDays(NotificationService::RETENTION_DAYS))
            ->distinct()
            ->pluck('type')
            ->map(fn (string $type) => Notification::make(['type' => $type])->category)
            ->filter()
            ->all();

        $wanted = [...array_map(fn (NotificationCategory $c) => $c->value, NotificationCategory::forRoles($user->getRoleNames())), ...$received];

        return array_values(array_filter(NotificationCategory::cases(), fn (NotificationCategory $case) => in_array($case->value, $wanted, true)));
    }
}
