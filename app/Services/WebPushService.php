<?php

namespace App\Services;

use App\Enums\NotificationPriority;
use App\Enums\NotificationType;
use App\Jobs\PushNotificationJob;
use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\User;
use App\Support\DailyFormSchedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Sprint 18 Sub 04 (K2) — sends a stored notification to every device its
 * recipient allowed (Web Push / VAPID, through the browser vendor's push
 * service). The payload is encrypted end-to-end for the device, but it is
 * shown on a lock screen, so it carries only what the bell shows — never an
 * amount of money (lockScreenText()) and no ids beyond the open link.
 *
 * P1 rings and stays on screen, P2 rings, P3 arrives silently, P4 is never
 * pushed (bell only) — see NotificationPriority.
 */
class WebPushService
{
    /** Lock-screen body length; the full message is one tap away. */
    private const BODY_LIMIT = 180;

    public static function enabled(): bool
    {
        return filled(config('services.webpush.public_key')) && filled(config('services.webpush.private_key'));
    }

    /** Worth queuing a push for this row at all? (Checked before dispatching PushNotificationJob.) */
    public static function shouldPush(Notification $notification, User $user): bool
    {
        return self::enabled()
            && self::allowedBy($user, $notification)
            && $user->pushSubscriptions()->exists();
    }

    /**
     * Pengaturan Notifikasi (Sub 05): a muted category is not pushed — except
     * P1 "klien menunggu", which still arrives, silently (mutedClientWaiting()).
     * P4 is never pushed.
     */
    private static function allowedBy(User $user, Notification $notification): bool
    {
        $priority = $notification->priority;

        if ($priority === NotificationPriority::Info->value) {
            return false;
        }

        return $priority === NotificationPriority::ClientWaiting->value
            || ! in_array($notification->category, $user->mutedNotificationCategories(), true);
    }

    private static function mutedClientWaiting(User $user, Notification $notification): bool
    {
        return $notification->priority === NotificationPriority::ClientWaiting->value
            && in_array($notification->category, $user->mutedNotificationCategories(), true);
    }

    /**
     * Sprint 18 Sub 06 (K3) — an unread P1 older than `after_minutes` rings
     * the devices once more ("Pengingat: …"), on working days within the
     * configured hours. `repushed_at` is claimed per row before the push is
     * queued, so overlapping runs never ring twice. No new bell row.
     *
     * @return int notifications re-pushed
     */
    public function repushUnopenedClientWaiting(): int
    {
        $config = config('daiku.notification_repush');
        $now = DailyFormSchedule::now();

        if (! self::enabled()
            || ! DailyFormSchedule::isWorkDay($now)
            || $now->format('H:i') < $config['from']
            || $now->format('H:i') >= $config['until']) {
            return 0;
        }

        $due = Notification::query()
            ->with('user')
            ->where('is_read', false)
            ->whereNull('repushed_at')
            ->whereIn('type', NotificationType::storedValuesOf(NotificationPriority::ClientWaiting))
            ->where('created_at', '<=', now()->subMinutes($config['after_minutes']))
            ->where('created_at', '>=', now()->subHours($config['max_age_hours']))
            ->oldest('id')
            ->get();

        $repushed = 0;

        foreach ($due as $notification) {
            $claimed = Notification::whereKey($notification->id)->whereNull('repushed_at')->update(['repushed_at' => now()]);

            if ($claimed === 1 && $notification->user && self::shouldPush($notification, $notification->user)) {
                PushNotificationJob::dispatch($notification, reminder: true);
                $repushed++;
            }
        }

        return $repushed;
    }

    /** @return int devices the push service accepted the message for */
    public function send(Notification $notification, bool $reminder = false): int
    {
        $user = $notification->user;
        $subscriptions = PushSubscription::where('user_id', $notification->user_id)->get();

        if (! self::enabled() || ! $user || ! self::allowedBy($user, $notification) || $subscriptions->isEmpty()) {
            return 0;
        }

        $priority = NotificationPriority::from($notification->priority);
        $payload = $this->payload($notification, $priority);

        if (self::mutedClientWaiting($user, $notification)) {
            $payload['silent'] = true;
        }

        if ($reminder) {
            $payload['title'] = "Pengingat: {$payload['title']}";
        }

        return $this->deliver(
            $subscriptions,
            $payload,
            $this->options($notification, $priority),
            ['notification_id' => $notification->id],
        );
    }

    /**
     * "Kirim notifikasi uji" (Pengaturan Notifikasi) — straight to the
     * user's own devices, no bell row. Sent synchronously so the page can
     * say how many devices took it.
     *
     * @return array{devices: int, delivered: int}
     */
    public function sendTest(User $user): array
    {
        $subscriptions = $user->pushSubscriptions()->get();

        if (! self::enabled() || $subscriptions->isEmpty()) {
            return ['devices' => $subscriptions->count(), 'delivered' => 0];
        }

        $delivered = $this->deliver($subscriptions, [
            'title' => 'Notifikasi uji',
            'body' => 'Notifikasi Daiku sudah aktif di perangkat ini.',
            'url' => route('notifications.index'),
            'tag' => "push_test:{$user->id}",
            'priority' => NotificationPriority::ActionRequired->value,
            'requireInteraction' => false,
            'silent' => false,
            'icon' => route('pwa.icon', ['size' => 192, 'purpose' => 'any']),
        ], ['TTL' => 600, 'urgency' => 'high'], ['test_for_user_id' => $user->id]);

        return ['devices' => $subscriptions->count(), 'delivered' => $delivered];
    }

    /**
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $logContext
     */
    private function deliver(Collection $subscriptions, array $payload, array $options, array $logContext): int
    {
        $client = app(WebPush::class);
        $payload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach ($subscriptions as $subscription) {
            $client->queueNotification(new Subscription(
                $subscription->endpoint,
                $subscription->public_key,
                $subscription->auth_token,
                $subscription->content_encoding,
            ), $payload, $options);
        }

        $byHash = $subscriptions->keyBy('endpoint_hash');
        $delivered = 0;

        foreach ($client->flush() as $report) {
            $subscription = $byHash->get(PushSubscription::hashEndpoint($report->getEndpoint()));

            if ($report->isSuccess()) {
                $subscription?->update(['last_used_at' => now()]);
                $delivered++;
            } elseif ($report->isSubscriptionExpired()) {
                // The user revoked permission or the browser dropped it.
                $subscription?->delete();
            } else {
                Log::warning('Web Push gagal terkirim', $logContext + [
                    'push_subscription_id' => $subscription?->id,
                    'reason' => $report->getReason(),
                ]);
            }
        }

        return $delivered;
    }

    /** @return array<string, mixed> read by public/sw.js */
    public function payload(Notification $notification, NotificationPriority $priority): array
    {
        return [
            'title' => $notification->title,
            'body' => Str::limit(self::lockScreenText($notification->message), self::BODY_LIMIT),
            'url' => route('notifications.open', $notification),
            'tag' => $this->tag($notification),
            'priority' => $priority->value,
            'requireInteraction' => $priority === NotificationPriority::ClientWaiting,
            'silent' => $priority === NotificationPriority::Update,
            'icon' => route('pwa.icon', ['size' => 192, 'purpose' => 'any']),
        ];
    }

    /** "Rp 33.000.000" never reaches a lock screen. */
    public static function lockScreenText(string $text): string
    {
        return (string) preg_replace('/Rp\s?[\d.,]+/u', 'Rp •••', $text);
    }

    /**
     * Same type about the same thing → one entry on the device (a repeated
     * reminder replaces the previous one instead of stacking up).
     */
    private function tag(Notification $notification): string
    {
        $metadata = $notification->metadata ?? [];

        foreach (['qa_form_id', 'quotation_id', 'invoice_id', 'design_id', 'project_id', 'lead_id'] as $key) {
            if (is_int($metadata[$key] ?? null)) {
                return "{$notification->type}:{$key}:{$metadata[$key]}";
            }
        }

        return "{$notification->type}:{$notification->id}";
    }

    /** @return array{TTL: int, urgency: string, topic: string} */
    private function options(Notification $notification, NotificationPriority $priority): array
    {
        return [
            // An undelivered (phone off) push older than this is no longer news.
            'TTL' => $priority === NotificationPriority::Update ? 12 * 3600 : 24 * 3600,
            'urgency' => match ($priority) {
                NotificationPriority::ClientWaiting => 'high',
                NotificationPriority::ActionRequired => 'normal',
                default => 'low',
            },
            // Push services replace a still-queued message with the same topic (≤ 32 url-safe chars).
            'topic' => substr(hash('sha256', $this->tag($notification)), 0, 32),
        ];
    }
}
