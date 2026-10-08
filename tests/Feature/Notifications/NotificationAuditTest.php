<?php

use App\Enums\DesignStatus;
use App\Enums\LeadSurveyStatus;
use App\Enums\NotificationPriority;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Jobs\DailyFormReminderJob;
use App\Jobs\LeadFollowUpReminderJob;
use App\Jobs\MaterialRequestReminderJob;
use App\Jobs\MilestoneOverdueJob;
use App\Jobs\PushNotificationJob;
use App\Jobs\RepushClientWaitingJob;
use App\Jobs\TaskOverdueJob;
use App\Jobs\TerminInvoiceReminderJob;
use App\Jobs\TerminOverdueJob;
use App\Jobs\TerminReminderJob;
use App\Models\Design;
use App\Models\Lead;
use App\Models\LeadSurvey;
use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\Task;
use App\Models\User;
use App\Services\ActionInboxService;
use App\Services\NotificationService;
use App\Services\TaskService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * Sprint 18 Sub 06 — the gaps the trigger audit closed: Tukang told about
 * PM edits, P1 notifications that had no "Perlu Tindakan" queue, one P1 per
 * client approval, and the one-time re-push of an unopened P1.
 */
beforeEach(fn () => $this->seed(RoleSeeder::class));

function notificationAuditUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function notificationAuditInbox(User $user, string $key): ?array
{
    return collect(app(ActionInboxService::class)->for($user, fresh: true))->firstWhere('key', $key);
}

/** @param  array<string, mixed>  $overrides */
function notificationAuditTaskEdit(Task $task, array $overrides, User $pm): Task
{
    return app(TaskService::class)->update($task, [
        'title' => $task->title,
        'description' => $task->description,
        'due_date' => $task->due_date->toDateString(),
        'priority' => $task->priority->value,
        'milestone_id' => $task->milestone_id,
        'assignee_id' => $task->assignee_id,
        'rate_per_task' => $task->rate_per_task,
        ...$overrides,
    ], $pm);
}

// ── Tukang told about PM edits ──────────────────────────────────────────

test('moving a deadline tells the tukang the old and new date', function () {
    $staff = notificationAuditUser('FIELD_STAFF');
    $task = Task::factory()->create(['assignee_id' => $staff->id, 'due_date' => '2026-10-20', 'status' => TaskStatus::Pending->value, 'milestone_id' => null]);

    notificationAuditTaskEdit($task, ['due_date' => '2026-10-25'], notificationAuditUser('PM'));

    $notification = Notification::where('user_id', $staff->id)->sole();
    expect($notification->type)->toBe('task_updated')
        ->and($notification->priority)->toBe('ACTION_REQUIRED')
        ->and($notification->message)->toContain('20 Oktober 2026')->toContain('25 Oktober 2026');
});

test('a changed title tells the tukang; a priority-only edit, a DONE task or a reassignment does not send task_updated', function () {
    $pm = notificationAuditUser('PM');
    $staff = notificationAuditUser('FIELD_STAFF');
    $task = Task::factory()->create(['assignee_id' => $staff->id, 'status' => TaskStatus::Pending->value, 'milestone_id' => null, 'due_date' => now()->addWeek()]);

    notificationAuditTaskEdit($task, ['title' => 'Pasang kabinet atas'], $pm);
    expect(Notification::where('user_id', $staff->id)->where('type', 'task_updated')->count())->toBe(1);

    notificationAuditTaskEdit($task->fresh(), ['priority' => $task->priority->value === 'HIGH' ? 'LOW' : 'HIGH'], $pm);
    expect(Notification::where('user_id', $staff->id)->count())->toBe(1);

    $done = Task::factory()->create(['assignee_id' => $staff->id, 'status' => TaskStatus::Done->value, 'milestone_id' => null]);
    notificationAuditTaskEdit($done, ['title' => 'Judul baru'], $pm);
    expect(Notification::where('user_id', $staff->id)->count())->toBe(1);

    $next = notificationAuditUser('FIELD_STAFF');
    notificationAuditTaskEdit($task->fresh(), ['assignee_id' => $next->id, 'title' => 'Lagi'], $pm);
    expect(Notification::where('user_id', $next->id)->pluck('type')->all())->toBe(['task_assigned'])
        ->and(Notification::where('user_id', $staff->id)->count())->toBe(1);
});

// ── Every P1 has a queue ────────────────────────────────────────────────

test('a client revision lands in the architect\'s own queue, not other architects\'', function () {
    $architect = notificationAuditUser('DESIGNER');
    $other = notificationAuditUser('DESIGNER');
    $mine = Design::factory()->create(['pic_id' => $architect->id, 'status' => DesignStatus::RevisiDesain->value]);
    Design::factory()->create(['pic_id' => $other->id, 'status' => DesignStatus::RevisiDesain->value]);
    Design::factory()->create(['pic_id' => $architect->id, 'status' => DesignStatus::Desain->value]);

    $revisions = notificationAuditInbox($architect, 'design-revision');
    expect($revisions['count'])->toBe(1)
        ->and($revisions['items'][0]['href'])->toBe(route('design.show', $mine))
        ->and($revisions['routeName'])->toBe('design.index')
        ->and(notificationAuditInbox($architect, 'design-work')['count'])->toBe(1);
});

test('a team member (not only the PIC) sees the revision too', function () {
    $architect = notificationAuditUser('DESIGNER');
    $design = Design::factory()->create(['pic_id' => notificationAuditUser('DESIGNER')->id, 'status' => DesignStatus::RevisiDesain->value]);
    $design->staff()->attach($architect->id);

    expect(notificationAuditInbox($architect, 'design-revision')['count'])->toBe(1);
});

test('a paid survey waits in its Marketing owner\'s queue until it is done', function () {
    $marketing = notificationAuditUser('MARKETING');
    $lead = Lead::factory()->create(['assigned_to' => $marketing->id]);
    $survey = LeadSurvey::factory()->create(['lead_id' => $lead->id, 'status' => LeadSurveyStatus::Siap->value]);
    LeadSurvey::factory()->create(['status' => LeadSurveyStatus::Siap->value]); // someone else's lead

    $group = notificationAuditInbox($marketing, 'survey-ready');
    expect($group['count'])->toBe(1)
        ->and($group['items'][0]['href'])->toBe(route('crm.leads.show', $lead));

    $survey->update(['status' => LeadSurveyStatus::Selesai->value]);
    expect(notificationAuditInbox($marketing, 'survey-ready'))->toBeNull();
});

test('every P1 type is one that a "Perlu Tindakan" queue or pop-up covers', function () {
    // The queue each P1 feeds — keep in step with the matrix in
    // .claude/plan/sprint-18-notifikasi.md (Sub 06) when a P1 is added.
    $covered = [
        'lead_follow_up_due' => 'follow-up',
        'lead_survey_ready' => 'survey-ready',
        'survey_to_schedule' => 'survey-schedule',
        'quotation_requested' => 'quotation-requested',
        'quotation_submitted' => 'quotation-pm',
        'quotation_awaiting_ceo' => 'quotation-ceo',
        'quotation_returned' => 'quotation-returned',
        'quotation_client_rejected' => 'quotation-returned',
        'quotation_ready_to_send' => 'quotation-send',
        'invoice_to_issue' => 'quotation-invoice',
        'invoice_awaiting_verification' => 'invoice-verify',
        'invoice_rejected' => 'invoice-proof',
        'design_revision_requested' => 'design-revision',
        'design_ready_to_assign' => 'design-assign',
        'design_acc' => 'quotation-requested',
        'project_opening_pending' => 'project-opening',
    ];

    expect(NotificationType::storedValuesOf(NotificationPriority::ClientWaiting))
        ->toEqualCanonicalizing([...array_keys($covered), 'quotation_rejected']);
});

test('a client approval rings Marketing once: the follow-up is the P1, the approval itself is news', function () {
    expect(NotificationType::QuotationClientApproved->priority())->toBe(NotificationPriority::Update)
        ->and(NotificationType::InvoiceToIssue->priority())->toBe(NotificationPriority::ClientWaiting)
        ->and(NotificationType::ProjectOpeningPending->priority())->toBe(NotificationPriority::ClientWaiting);
});

// ── Re-push of an unopened P1 ───────────────────────────────────────────

function notificationRepushReady(): User
{
    config(['services.webpush.public_key' => 'pub', 'services.webpush.private_key' => 'priv']);
    $user = notificationAuditUser('ESTIMATOR');
    PushSubscription::factory()->create(['user_id' => $user->id]);

    return $user;
}

test('an unopened P1 rings once more after an hour in working hours — once', function () {
    Queue::fake([PushNotificationJob::class]); // the repush job itself must really run
    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00', 'Asia/Jakarta')); // Selasa
    $user = notificationRepushReady();
    $due = Notification::factory()->create(['user_id' => $user->id, 'type' => 'quotation_requested', 'created_at' => now()->subMinutes(61)]);
    Notification::factory()->create(['user_id' => $user->id, 'type' => 'quotation_requested', 'created_at' => now()->subMinutes(30)]);  // too fresh
    Notification::factory()->create(['user_id' => $user->id, 'type' => 'quotation_requested', 'created_at' => now()->subHours(25)]);    // too old
    Notification::factory()->create(['user_id' => $user->id, 'type' => 'quotation_requested', 'created_at' => now()->subHours(2), 'is_read' => true]);
    Notification::factory()->create(['user_id' => $user->id, 'type' => 'task_assigned', 'created_at' => now()->subHours(2)]);        // P2

    dispatch_sync(new RepushClientWaitingJob);
    dispatch_sync(new RepushClientWaitingJob);

    Queue::assertPushed(PushNotificationJob::class, 1);
    Queue::assertPushed(PushNotificationJob::class, fn (PushNotificationJob $job) => $job->reminder && $job->notification->is($due));
    expect($due->fresh()->repushed_at)->not->toBeNull()
        ->and(Notification::count())->toBe(5);

    Carbon::setTestNow();
});

test('no re-push outside working hours, on Sunday, or for users without a device', function (string $at, bool $withDevice) {
    Queue::fake([PushNotificationJob::class]); // the repush job itself must really run
    Carbon::setTestNow(Carbon::parse($at, 'Asia/Jakarta'));
    $user = $withDevice ? notificationRepushReady() : tap(notificationAuditUser('ESTIMATOR'), fn () => config(['services.webpush.public_key' => 'pub', 'services.webpush.private_key' => 'priv']));
    Notification::factory()->create(['user_id' => $user->id, 'type' => 'invoice_to_issue', 'created_at' => now()->subHours(2)]);

    dispatch_sync(new RepushClientWaitingJob);

    Queue::assertNotPushed(PushNotificationJob::class);
    Carbon::setTestNow();
})->with([
    'malam' => ['2026-10-06 19:00', true],
    'Minggu' => ['2026-10-11 10:00', true],
    'tanpa perangkat' => ['2026-10-06 10:00', false],
]);

// ── Scheduled reminders don't double up ─────────────────────────────────

test('every reminder job, run twice on the demo data, adds nothing the second time', function () {
    Queue::fake([PushNotificationJob::class]);
    $this->seed(DatabaseSeeder::class);
    Carbon::setTestNow(Carbon::parse('2026-10-06 20:35', 'Asia/Jakarta')); // Selasa, after the form reminder hour

    $jobs = [
        new LeadFollowUpReminderJob, new TerminReminderJob, new TerminInvoiceReminderJob, new TerminOverdueJob,
        new MaterialRequestReminderJob, new TaskOverdueJob, new MilestoneOverdueJob, new DailyFormReminderJob,
    ];

    foreach ($jobs as $job) {
        dispatch_sync($job);
    }
    $afterFirst = Notification::count();

    foreach ($jobs as $job) {
        dispatch_sync(clone $job);
    }

    expect(Notification::count())->toBe($afterFirst);
    Carbon::setTestNow();
});

test('notify still records a P1 for a user with every category muted', function () {
    $user = notificationAuditUser('MARKETING');
    $user->forceFill(['notification_preferences' => ['muted_categories' => ['FINANCE', 'CRM', 'QUOTATION'], 'sound' => false]])->save();

    app(NotificationService::class)->notify($user, NotificationType::InvoiceToIssue, 'Terbitkan Invoice', 'Pesan');

    expect(Notification::where('user_id', $user->id)->count())->toBe(1);
});
