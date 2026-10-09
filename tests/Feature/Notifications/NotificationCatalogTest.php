<?php

use App\Enums\NotificationPriority;
use App\Enums\NotificationType;
use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\NotificationTarget;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function notificationRecipient(string $role = 'PM'): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** Every id a trigger may put in metadata, so each target branch builds a real URL. */
const NOTIFICATION_SAMPLE_METADATA = [
    'qa_form_id' => 1, 'quotation_id' => 1, 'design_id' => 1, 'project_id' => 1, 'lead_id' => 1,
    'invoice_id' => 1, 'employee_id' => 1, 'performance_review_id' => 1,
];

// ── Catalog (Sprint 18 Sub 02) ──────────────────────────────────────────

test('every notification type has a priority, a category and a target that resolves', function (NotificationType $type) {
    expect($type->priority())->toBeInstanceOf(NotificationPriority::class)
        ->and($type->category()->label())->not->toBeEmpty();

    foreach ([[], NOTIFICATION_SAMPLE_METADATA] as $metadata) {
        $notification = Notification::factory()->make(['type' => $type->value, 'metadata' => $metadata]);

        expect(NotificationTarget::for($notification))->toStartWith(config('app.url'));
    }
})->with(NotificationType::cases());

test('K3: priority follows who is kept waiting', function (NotificationType $type, NotificationPriority $priority) {
    expect($type->priority())->toBe($priority);
})->with([
    'klien menunggu RAB' => [NotificationType::QuotationRequested, NotificationPriority::ClientWaiting],
    'klien sudah transfer' => [NotificationType::InvoiceAwaitingVerification, NotificationPriority::ClientWaiting],
    'CEO buka proyek' => [NotificationType::ProjectOpeningPending, NotificationPriority::ClientWaiting],
    'estimator berutang RAB proyek' => [NotificationType::DesignAcc, NotificationPriority::ClientWaiting],
    'PM hanya diberi tahu' => [NotificationType::DesignAccPm, NotificationPriority::Update],
    'tugas tukang' => [NotificationType::TaskAssigned, NotificationPriority::ActionRequired],
    'antrean Finance lembur' => [NotificationType::OvertimeAwaitingFinance, NotificationPriority::ActionRequired],
    'kabar ke tukang' => [NotificationType::OvertimeApprovedPm, NotificationPriority::Update],
    'pembayaran terverifikasi berbunyi (Sprint 19)' => [NotificationType::InvoiceVerified, NotificationPriority::ActionRequired],
    'survey lunas belum dijadwalkan' => [NotificationType::SurveyToSchedule, NotificationPriority::ClientWaiting],
    'jadwal survey ke CEO & PM' => [NotificationType::SurveyScheduled, NotificationPriority::ActionRequired],
    'survey jadi berangkat' => [NotificationType::SurveyConfirmed, NotificationPriority::Update],
    'desain siap dikirim ke klien (Sprint 22)' => [NotificationType::DesignReadyToSend, NotificationPriority::ClientWaiting],
    'proyek selesai' => [NotificationType::ProjectCompleted, NotificationPriority::Info],
]);

test('rows written before a type was split still resolve; unknown types read as Info', function () {
    $legacy = Notification::factory()->create(['type' => 'quotation_rejected', 'metadata' => ['quotation_id' => 7]]);
    $unknown = Notification::factory()->create(['type' => 'something_removed']);

    expect($legacy->notificationType())->toBe(NotificationType::QuotationReturned)
        ->and($legacy->priority)->toBe('CLIENT_WAITING')
        ->and(NotificationTarget::for($legacy))->toBe(route('quotations.show', 7))
        ->and($unknown->notificationType())->toBeNull()
        ->and($unknown->priority)->toBe('INFO')
        ->and($unknown->category)->toBeNull()
        ->and(NotificationTarget::for($unknown))->toBe(route('notifications.index'));
});

// ── Targets (ported from lib/notificationHref.ts) ───────────────────────

test('targets: the most specific page wins', function (string $type, array $metadata, Closure $expected) {
    $notification = Notification::factory()->make(['type' => $type, 'metadata' => $metadata]);

    expect(NotificationTarget::for($notification))->toBe($expected());
})->with([
    'tugas tukang → daftar tugas, bukan proyek' => ['task_assigned', ['project_id' => 3], fn () => route('tasks.index')],
    'QA → form QA, bukan proyek' => ['qa_approved', ['qa_form_id' => 4, 'project_id' => 3], fn () => route('qa-forms.show', 4)],
    'desain → desain, bukan lead' => ['design_assigned', ['design_id' => 5, 'lead_id' => 2], fn () => route('design.show', 5)],
    'bukti ditolak → dialog Kirim Bukti Bayar' => ['invoice_rejected', ['invoice_id' => 9], fn () => route('finance.invoices.index', ['awaiting_proof' => 1, 'proof' => 9])],
    'bukti masuk → antrean verifikasi' => ['invoice_awaiting_verification', ['invoice_id' => 9], fn () => route('finance.invoices.verification')],
    'SP → Kinerja Saya' => ['disciplinary_issued', [], fn () => route('my.index', ['tab' => 'discipline'])],
    'gaji diputuskan → tab gaji karyawan' => ['salary_change_decided', ['employee_id' => 6], fn () => route('hr.employees.show', ['employee' => 6, 'tab' => 'salary'])],
    'lead follow-up → lead' => ['lead_follow_up_due', ['lead_id' => 2], fn () => route('crm.leads.show', 2)],
]);

// ── notifications.open ───────────────────────────────────────────────────

test('opening a notification marks it read with a timestamp and redirects to its target', function () {
    $user = notificationRecipient('ESTIMATOR');
    $notification = Notification::factory()->create([
        'user_id' => $user->id,
        'type' => NotificationType::QuotationRequested->value,
        'metadata' => ['quotation_id' => 12, 'lead_id' => 3],
    ]);

    $this->actingAs($user)->get(route('notifications.open', $notification))
        ->assertRedirect(route('quotations.show', 12));

    expect($notification->fresh()->is_read)->toBeTrue()
        ->and($notification->fresh()->read_at)->not->toBeNull();
});

test('opening an already-read notification keeps its first read time', function () {
    $user = notificationRecipient();
    $firstRead = now()->subHour()->startOfSecond();
    $notification = Notification::factory()->create(['user_id' => $user->id, 'is_read' => true, 'read_at' => $firstRead]);

    $this->actingAs($user)->get(route('notifications.open', $notification))->assertRedirect();

    expect($notification->fresh()->read_at->equalTo($firstRead))->toBeTrue();
});

test('nobody can open someone else notification', function () {
    $notification = Notification::factory()->create();

    $this->actingAs(notificationRecipient('CEO'))->get(route('notifications.open', $notification))->assertForbidden();

    expect($notification->fresh()->is_read)->toBeFalse();
});

test('guests are sent to login instead of opening a notification', function () {
    $this->get(route('notifications.open', Notification::factory()->create()))->assertRedirect(route('login'));
});

test('mark one / mark all stamp read_at', function () {
    $user = notificationRecipient();
    [$one, $two] = Notification::factory()->count(2)->create(['user_id' => $user->id]);

    $this->actingAs($user)->patch(route('notifications.markAsRead', $one));
    expect($one->fresh()->read_at)->not->toBeNull()
        ->and($two->fresh()->read_at)->toBeNull();

    app(NotificationService::class)->markAllAsRead($user);
    expect($two->fresh()->read_at)->not->toBeNull();
});

test('the bell props carry priority and category', function () {
    $user = notificationRecipient('MARKETING');
    Notification::factory()->create(['user_id' => $user->id, 'type' => NotificationType::InvoiceToIssue->value]);

    $this->actingAs($user)->get(route('notifications.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('notifications.0.priority', 'CLIENT_WAITING')
            ->where('notifications.0.category', 'FINANCE')
            ->where('items.data.0.priority', 'CLIENT_WAITING'));
});

// ── Live bell (Sprint 18 Sub 03) ─────────────────────────────────────────

test('the bell lists unread "klien menunggu" first, even when older', function () {
    $user = notificationRecipient('ESTIMATOR');
    $p1 = Notification::factory()->create([
        'user_id' => $user->id,
        'type' => NotificationType::QuotationRequested->value,
        'created_at' => now()->subDays(3),
    ]);
    $legacyP1 = Notification::factory()->create([
        'user_id' => $user->id,
        'type' => 'quotation_rejected',
        'created_at' => now()->subDays(4),
    ]);
    Notification::factory()->count(10)->create(['user_id' => $user->id, 'type' => NotificationType::ProjectCompleted->value]);

    $this->actingAs($user)->get(route('notifications.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('notifications', 10)
            ->where('notifications.0.id', $p1->id)
            ->where('notifications.1.id', $legacyP1->id)
            ->where('unreadNotificationsCount', 12));
});

test('a new notification drops the recipient cached "Perlu Tindakan" counts', function () {
    $user = notificationRecipient('MARKETING');
    Cache::put("inbox:{$user->id}", ['stale'], 60);

    app(NotificationService::class)->notify($user, NotificationType::InvoiceToIssue, 'Judul', 'Pesan');

    expect(Cache::has("inbox:{$user->id}"))->toBeFalse();
});

test('the live payload carries priority and category for the toast', function () {
    $notification = Notification::factory()->create(['type' => NotificationType::InvoiceAwaitingVerification->value]);

    expect((new NotificationCreated($notification))->broadcastWith())
        ->toMatchArray(['id' => $notification->id, 'priority' => 'CLIENT_WAITING', 'category' => 'FINANCE']);
});

test('Reverb only accepts listening browsers, and production origins come from the env', function () {
    $app = config('reverb.apps.apps.0');

    expect($app['accept_client_events_from'])->toBe('none')
        ->and($app['allowed_origins'])->toBe(['*']);
});
