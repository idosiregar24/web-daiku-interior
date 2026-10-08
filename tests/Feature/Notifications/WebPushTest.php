<?php

use App\Enums\NotificationPriority;
use App\Enums\NotificationType;
use App\Jobs\PushNotificationJob;
use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\WebPushService;
use Database\Seeders\RoleSeeder;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Mockery\MockInterface;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    config(['services.webpush.public_key' => 'test-public-key', 'services.webpush.private_key' => 'test-private-key']);
});

function pushUser(string $role = 'ESTIMATOR'): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function browserSubscription(array $overrides = []): array
{
    return array_replace_recursive([
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/device-'.uniqid(),
        'keys' => ['p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQ', 'auth' => 'tBHItJI5svbpez7KI4CCXg'],
        'content_encoding' => 'aes128gcm',
    ], $overrides);
}

/**
 * A fake push service: records what was queued, answers each endpoint with
 * the given HTTP status (201 when unlisted).
 *
 * @param  array<string, int>  $statusByEndpoint
 * @param  array<int, array{subscription: Subscription, payload: array, options: array}>  $sent
 */
function fakePushService(array $statusByEndpoint = [], ?array &$sent = null): void
{
    $sent = [];
    $queued = [];

    test()->mock(WebPush::class, function (MockInterface $mock) use ($statusByEndpoint, &$sent, &$queued) {
        $mock->shouldReceive('queueNotification')->andReturnUsing(
            function (Subscription $subscription, string $payload, array $options) use (&$sent, &$queued) {
                $sent[] = ['subscription' => $subscription, 'payload' => json_decode($payload, true), 'options' => $options];
                $queued[] = $subscription->getEndpoint();
            },
        );
        $mock->shouldReceive('flush')->andReturnUsing(function () use ($statusByEndpoint, &$queued) {
            foreach ($queued as $endpoint) {
                $status = $statusByEndpoint[$endpoint] ?? 201;

                yield new MessageSentReport(new Request('POST', $endpoint), new Response($status), $status < 300, (string) $status);
            }
            $queued = [];
        });
    });
}

// ── Devices (own rows only) ──────────────────────────────────────────────

test('a user registers this device for push', function () {
    $user = pushUser();
    $data = browserSubscription();

    $this->actingAs($user)->post(route('push-subscriptions.store'), $data, ['User-Agent' => 'Chrome Android'])->assertRedirect();

    $row = PushSubscription::sole();
    expect($row->user_id)->toBe($user->id)
        ->and($row->endpoint)->toBe($data['endpoint'])
        ->and($row->endpoint_hash)->toBe(hash('sha256', $data['endpoint']))
        ->and($row->user_agent)->toBe('Chrome Android');
});

test('a device re-registered after another login moves to the new user', function () {
    $first = pushUser();
    $second = pushUser('MARKETING');
    $data = browserSubscription();

    $this->actingAs($first)->post(route('push-subscriptions.store'), $data);
    $this->actingAs($second)->post(route('push-subscriptions.store'), $data);

    expect(PushSubscription::count())->toBe(1)
        ->and(PushSubscription::sole()->user_id)->toBe($second->id);
});

test('only https push endpoints with keys are accepted', function (array $overrides, string $field) {
    $this->actingAs(pushUser())
        ->post(route('push-subscriptions.store'), browserSubscription($overrides))
        ->assertSessionHasErrors($field);

    expect(PushSubscription::count())->toBe(0);
})->with([
    'http' => [['endpoint' => 'http://push.example.com/abc'], 'endpoint'],
    'bukan URL' => [['endpoint' => 'javascript:alert(1)'], 'endpoint'],
    'tanpa kunci' => [['keys' => ['p256dh' => '']], 'keys.p256dh'],
    'encoding asing' => [['content_encoding' => 'gzip'], 'content_encoding'],
]);

test('turning push off on a device removes only that user row', function () {
    $user = pushUser();
    $other = pushUser();
    $mine = PushSubscription::factory()->create(['user_id' => $user->id]);
    $theirs = PushSubscription::factory()->create(['user_id' => $other->id]);

    $this->actingAs($user)->delete(route('push-subscriptions.unsubscribe'), ['endpoint' => $theirs->endpoint]);
    expect($theirs->fresh())->not->toBeNull();

    $this->actingAs($user)->delete(route('push-subscriptions.unsubscribe'), ['endpoint' => $mine->endpoint]);
    expect($mine->fresh())->toBeNull();
});

test('a device can be removed from the list by its owner only', function () {
    $owner = pushUser();
    $device = PushSubscription::factory()->create(['user_id' => $owner->id]);

    $this->actingAs(pushUser('CEO'))->delete(route('push-subscriptions.destroy', $device))->assertForbidden();
    expect($device->fresh())->not->toBeNull();

    $this->actingAs($owner)->delete(route('push-subscriptions.destroy', $device))->assertRedirect();
    expect($device->fresh())->toBeNull();
});

test('guests cannot register devices', function () {
    $this->post(route('push-subscriptions.store'), browserSubscription())->assertRedirect(route('login'));
});

test('the public key is shared only when push is configured', function () {
    $user = pushUser();

    $this->actingAs($user)->get(route('notifications.index'))
        ->assertInertia(fn (Assert $page) => $page->where('webPushKey', 'test-public-key'));

    config(['services.webpush.private_key' => null]);
    $this->actingAs($user)->get(route('notifications.index'))
        ->assertInertia(fn (Assert $page) => $page->where('webPushKey', null));
});

// ── Dispatch ────────────────────────────────────────────────────────────

test('a push job is queued only for P1–P3 rows of users with a device, when configured', function () {
    Queue::fake();
    $withDevice = pushUser();
    PushSubscription::factory()->create(['user_id' => $withDevice->id]);
    $without = pushUser();
    $service = app(NotificationService::class);

    $service->notify($withDevice, NotificationType::QuotationRequested, 'P1', 'Pesan');   // pushed
    $service->notify($withDevice, NotificationType::InvoiceVerified, 'P3', 'Pesan');      // pushed (silent)
    $service->notify($withDevice, NotificationType::ProjectCompleted, 'P4', 'Pesan');     // bell only
    $service->notify($without, NotificationType::QuotationRequested, 'P1', 'Pesan');      // no device

    Queue::assertPushedOn('notifications', PushNotificationJob::class);
    Queue::assertPushed(PushNotificationJob::class, 2);

    config(['services.webpush.public_key' => null]);
    $service->notify($withDevice, NotificationType::QuotationRequested, 'P1', 'Pesan');
    Queue::assertPushed(PushNotificationJob::class, 2);
});

// ── Sending ─────────────────────────────────────────────────────────────

test('a P1 push rings, stays on screen, opens the notification and hides amounts', function () {
    $user = pushUser('CEO');
    PushSubscription::factory()->count(2)->create(['user_id' => $user->id]);
    fakePushService(sent: $sent);
    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'type' => NotificationType::QuotationAwaitingCeo->value,
        'title' => 'RAB Proyek Menunggu Approval CEO',
        'message' => 'RAB Proyek "Ibu Sari" (Rp 33.000.000) sudah di-ACC PM.',
        'metadata' => ['quotation_id' => 12],
    ]);

    expect(app(WebPushService::class)->send($notification))->toBe(2)
        ->and($sent)->toHaveCount(2);

    $payload = $sent[0]['payload'];
    expect($payload['title'])->toBe('RAB Proyek Menunggu Approval CEO')
        ->and($payload['body'])->toBe('RAB Proyek "Ibu Sari" (Rp •••) sudah di-ACC PM.')
        ->and($payload['url'])->toBe(route('notifications.open', $notification))
        ->and($payload['tag'])->toBe('quotation_awaiting_ceo:quotation_id:12')
        ->and($payload['requireInteraction'])->toBeTrue()
        ->and($payload['silent'])->toBeFalse()
        ->and($sent[0]['options']['urgency'])->toBe('high')
        ->and(strlen($sent[0]['options']['topic']))->toBe(32)
        ->and(PushSubscription::whereNull('last_used_at')->count())->toBe(0);
});

test('a P3 push arrives silently; a P4 is never pushed', function () {
    $user = pushUser();
    PushSubscription::factory()->create(['user_id' => $user->id]);
    fakePushService(sent: $sent);
    $service = app(WebPushService::class);

    $service->send(Notification::factory()->create(['user_id' => $user->id, 'type' => NotificationType::InvoiceVerified->value]));
    expect($sent[0]['payload']['silent'])->toBeTrue()
        ->and($sent[0]['payload']['requireInteraction'])->toBeFalse()
        ->and($sent[0]['options']['urgency'])->toBe('low');

    expect($service->send(Notification::factory()->create(['user_id' => $user->id, 'type' => NotificationType::ProjectCompleted->value])))->toBe(0);
});

test('a device the push service no longer knows is removed; others are kept', function () {
    $user = pushUser();
    $gone = PushSubscription::factory()->create(['user_id' => $user->id]);
    $failing = PushSubscription::factory()->create(['user_id' => $user->id]);
    $fine = PushSubscription::factory()->create(['user_id' => $user->id]);
    fakePushService([$gone->endpoint => 410, $failing->endpoint => 500]);

    $delivered = app(WebPushService::class)->send(
        Notification::factory()->create(['user_id' => $user->id, 'type' => NotificationType::TaskAssigned->value]),
    );

    expect($delivered)->toBe(1)
        ->and($gone->fresh())->toBeNull()
        ->and($failing->fresh())->not->toBeNull()
        ->and($failing->fresh()->last_used_at)->toBeNull()
        ->and($fine->fresh()->last_used_at)->not->toBeNull();
});

test('pushes go only to the recipient devices', function () {
    $user = pushUser();
    PushSubscription::factory()->create(['user_id' => $user->id]);
    PushSubscription::factory()->count(3)->create();
    fakePushService(sent: $sent);

    app(WebPushService::class)->send(Notification::factory()->create(['user_id' => $user->id, 'type' => NotificationType::TaskAssigned->value]));

    expect($sent)->toHaveCount(1);
});

test('the test push reports how many devices took it', function () {
    $user = pushUser();
    PushSubscription::factory()->count(2)->create(['user_id' => $user->id]);
    fakePushService(sent: $sent);

    $this->actingAs($user)->post(route('push-subscriptions.test'))
        ->assertSessionHas('success', 'Notifikasi uji dikirim ke 2 dari 2 perangkat.');

    expect($sent[0]['payload']['url'])->toBe(route('notifications.index'))
        ->and(Notification::count())->toBe(0);

    $this->actingAs(pushUser())->post(route('push-subscriptions.test'))
        ->assertSessionHas('error', 'Belum ada perangkat yang mengaktifkan notifikasi.');
});

// ── Pengaturan Notifikasi (Sub 05) ──────────────────────────────────────

test('a muted category is not pushed, but still lands in the bell', function () {
    Queue::fake();
    $user = pushUser('FIELD_STAFF');
    $user->forceFill(['notification_preferences' => ['muted_categories' => ['TASK'], 'sound' => true]])->save();
    PushSubscription::factory()->create(['user_id' => $user->id]);

    $notification = app(NotificationService::class)->notify($user, NotificationType::TaskAssigned, 'Task Baru', 'Pasang kabinet');

    Queue::assertNotPushed(PushNotificationJob::class);
    expect($notification->exists)->toBeTrue()
        ->and(app(WebPushService::class)->send($notification))->toBe(0);
});

test('"klien menunggu" in a muted category still arrives, only silently', function () {
    $user = pushUser('MARKETING');
    $user->forceFill(['notification_preferences' => ['muted_categories' => ['FINANCE'], 'sound' => true]])->save();
    PushSubscription::factory()->create(['user_id' => $user->id]);
    fakePushService(sent: $sent);
    $notification = Notification::factory()->create(['user_id' => $user->id, 'type' => NotificationType::InvoiceToIssue->value]);

    expect(WebPushService::shouldPush($notification, $user))->toBeTrue()
        ->and(app(WebPushService::class)->send($notification))->toBe(1)
        ->and($sent[0]['payload']['silent'])->toBeTrue()
        ->and($sent[0]['payload']['requireInteraction'])->toBeTrue();
});

test('a re-push says it is a reminder', function () {
    $user = pushUser();
    PushSubscription::factory()->create(['user_id' => $user->id]);
    fakePushService(sent: $sent);
    $notification = Notification::factory()->create(['user_id' => $user->id, 'type' => NotificationType::QuotationRequested->value, 'title' => 'Permintaan RAB']);

    app(WebPushService::class)->send($notification, reminder: true);

    expect($sent[0]['payload']['title'])->toBe('Pengingat: Permintaan RAB')
        ->and($sent[0]['payload']['tag'])->toBe(app(WebPushService::class)->payload($notification, NotificationPriority::ClientWaiting)['tag']);
});

test('an iPhone (Apple push service) always gets aes128gcm, even if saved as aesgcm', function () {
    $user = pushUser();
    PushSubscription::factory()->create([
        'user_id' => $user->id,
        'endpoint' => 'https://web.push.apple.com/QGuQ-abc',
        'endpoint_hash' => hash('sha256', 'https://web.push.apple.com/QGuQ-abc'),
        'content_encoding' => 'aesgcm',
    ]);
    PushSubscription::factory()->create(['user_id' => $user->id, 'content_encoding' => 'aesgcm']); // a non-Apple legacy row keeps its own
    fakePushService(sent: $sent);

    app(WebPushService::class)->send(Notification::factory()->create(['user_id' => $user->id, 'type' => NotificationType::TaskAssigned->value]));

    $encodings = collect($sent)->mapWithKeys(fn ($m) => [$m['subscription']->getEndpoint() => $m['subscription']->getContentEncoding()]);
    expect($encodings['https://web.push.apple.com/QGuQ-abc'])->toBe('aes128gcm')
        ->and($encodings->filter(fn ($e, $endpoint) => ! str_contains($endpoint, 'apple'))->first())->toBe('aesgcm');
});

test('a failed test push says what the push service answered', function () {
    $user = pushUser();
    $device = PushSubscription::factory()->create(['user_id' => $user->id]);
    test()->mock(WebPush::class, function (MockInterface $mock) use ($device) {
        $mock->shouldReceive('queueNotification');
        $mock->shouldReceive('flush')->andReturnUsing(function () use ($device) {
            yield new MessageSentReport(new Request('POST', $device->endpoint), new Response(403, [], '{"reason":"BadJwtToken"}'), false, 'Forbidden');
        });
    });

    $this->actingAs($user)->post(route('push-subscriptions.test'))
        ->assertSessionHas('error', 'Notifikasi uji gagal terkirim (403 BadJwtToken) — matikan lalu aktifkan lagi notifikasi di perangkat ini. Bila tetap gagal, admin server menjalankan php artisan daiku:push-check.');

    expect($device->fresh())->not->toBeNull();
});

test('other throttled requests do not use up the test push limit', function () {
    $user = pushUser();
    PushSubscription::factory()->create(['user_id' => $user->id]);
    fakePushService();

    // Browsing before the tap: marking notifications read, the app icon…
    foreach (range(1, 8) as $ignored) {
        $this->actingAs($user)->patch(route('notifications.markAllAsRead'))->assertRedirect();
        $this->actingAs($user)->get(route('pwa.icon', ['size' => 192, 'purpose' => 'any']))->assertSuccessful();
    }

    $this->actingAs($user)->post(route('push-subscriptions.test'))->assertSessionHas('success');

    foreach (range(1, 4) as $ignored) {
        $this->actingAs($user)->post(route('push-subscriptions.test'));
    }

    $this->actingAs($user)->post(route('push-subscriptions.test'))->assertTooManyRequests();
});
