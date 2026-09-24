<?php

use App\Enums\MilestoneStatus;
use App\Enums\QaStatus;
use App\Enums\TaskStatus;
use App\Enums\TerminStatus;
use App\Events\NotificationCreated;
use App\Jobs\DailyFormReminderJob;
use App\Models\DailyTaskForm;
use App\Models\Milestone;
use App\Models\Notification;
use App\Models\OvertimeRequest;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Task;
use App\Models\Termin;
use App\Models\User;
use App\Services\MilestoneService;
use App\Services\NotificationService;
use App\Services\OvertimeService;
use App\Services\PenaltyService;
use App\Services\QaFormService;
use App\Services\QuotationService;
use App\Services\TaskService;
use App\Services\TerminService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function userWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function notificationsFor(User $user, ?string $type = null)
{
    return Notification::where('user_id', $user->id)->when($type, fn ($q) => $q->where('type', $type))->get();
}

// ── Endpoints (PRD §7.1 "Notification (own)") ───────────────────────────

test('every role can open their own notification history', function (string $role) {
    $user = userWithRole($role);
    Notification::factory()->count(2)->create(['user_id' => $user->id]);
    Notification::factory()->create(); // someone else's

    $this->actingAs($user)->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Notifications/Index')->has('items.data', 2));
})->with(['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'QA', 'FINANCE', 'LOGISTICS', 'FIELD_STAFF']);

test('the unread filter only lists unread notifications', function () {
    $user = userWithRole('PM');
    Notification::factory()->create(['user_id' => $user->id, 'is_read' => true]);
    Notification::factory()->create(['user_id' => $user->id, 'is_read' => false]);

    $this->actingAs($user)->get(route('notifications.index', ['unread' => 1]))
        ->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.data.0.is_read', false));
});

test('history hides notifications older than 90 days', function () {
    $user = userWithRole('PM');
    Notification::factory()->create(['user_id' => $user->id, 'created_at' => now()->subDays(91)]);
    Notification::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->get(route('notifications.index'))
        ->assertInertia(fn (Assert $page) => $page->has('items.data', 1));
});

test('mark all as read only touches the current user', function () {
    $user = userWithRole('PM');
    $other = userWithRole('PM');
    Notification::factory()->count(3)->create(['user_id' => $user->id]);
    Notification::factory()->create(['user_id' => $other->id]);

    $this->actingAs($user)->patch(route('notifications.markAllAsRead'))->assertRedirect();

    expect(Notification::where('user_id', $user->id)->where('is_read', false)->count())->toBe(0)
        ->and(Notification::where('user_id', $other->id)->where('is_read', false)->count())->toBe(1);
});

test('a user cannot mark someone else notification as read', function () {
    $notification = Notification::factory()->create();

    $this->actingAs(userWithRole('CEO'))
        ->patch(route('notifications.markAsRead', $notification))
        ->assertForbidden();

    expect($notification->fresh()->is_read)->toBeFalse();
});

test('the shared bell props carry the real unread count', function () {
    $user = userWithRole('FINANCE');
    Notification::factory()->count(12)->create(['user_id' => $user->id]);

    $this->actingAs($user)->get(route('notifications.index'))
        ->assertInertia(fn (Assert $page) => $page->has('notifications', 10)->where('unreadNotificationsCount', 12));
});

test('pruning deletes only notifications past the retention window', function () {
    Notification::factory()->create(['created_at' => now()->subDays(91)]);
    Notification::factory()->create(['created_at' => now()->subDays(89)]);

    expect(app(NotificationService::class)->pruneOld())->toBe(1)
        ->and(Notification::count())->toBe(1);
});

// ── Delivery ─────────────────────────────────────────────────────────────

test('notify broadcasts NotificationCreated on the recipient private channel', function () {
    Event::fake([NotificationCreated::class]);
    $user = userWithRole('PM');

    app(NotificationService::class)->notify($user, 'test', 'Judul', 'Pesan');

    Event::assertDispatched(
        NotificationCreated::class,
        fn (NotificationCreated $event) => $event->broadcastOn()[0]->name === "private-App.Models.User.{$user->id}",
    );
});

test('notifications inside a rolled-back transaction are neither kept nor broadcast', function () {
    Event::fake([NotificationCreated::class]);
    $user = userWithRole('PM');

    try {
        DB::transaction(function () use ($user) {
            app(NotificationService::class)->notify($user, 'test', 'Judul', 'Pesan');

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(Notification::count())->toBe(0);
    Event::assertNotDispatched(NotificationCreated::class);
});

test('a broadcast failure never breaks the action that notified', function () {
    Broadcast::shouldReceive('event')->andThrow(new RuntimeException('Soketi down'));
    $user = userWithRole('PM');

    $notification = app(NotificationService::class)->notify($user, 'test', 'Judul', 'Pesan');

    expect($notification->exists)->toBeTrue();
});

test('notifyMany sends one row per person even if listed twice', function () {
    $user = userWithRole('CEO');

    app(NotificationService::class)->notifyMany([$user, $user, null], 'test', 'Judul', 'Pesan');

    expect(notificationsFor($user))->toHaveCount(1);
});

test('notifyRoles skips deactivated users', function () {
    $active = userWithRole('FINANCE');
    $inactive = userWithRole('FINANCE');
    $inactive->update(['is_active' => false]);

    app(NotificationService::class)->notifyRoles(['FINANCE'], 'test', 'Judul', 'Pesan');

    expect(notificationsFor($active))->toHaveCount(1)
        ->and(notificationsFor($inactive))->toHaveCount(0);
});

// ── PRD §4.9 triggers ────────────────────────────────────────────────────

test('trigger: submitting a quotation notifies CEO and PM', function () {
    $ceo = userWithRole('CEO');
    $pm = userWithRole('PM');
    $quotation = Quotation::factory()->create();
    QuotationItem::factory()->create(['quotation_id' => $quotation->id]);

    app(QuotationService::class)->submit($quotation);

    expect(notificationsFor($ceo, 'quotation_submitted'))->toHaveCount(1)
        ->and(notificationsFor($pm, 'quotation_submitted'))->toHaveCount(1);
});

test('trigger: a quotation decision notifies its estimator and the lead marketing', function () {
    $ceo = userWithRole('CEO');
    $quotation = Quotation::factory()->create(['status' => 'SUBMITTED']);

    app(QuotationService::class)->ceoDecision($quotation, 'reject', $ceo, 'Harga terlalu tinggi');

    expect(notificationsFor($quotation->creator, 'quotation_rejected'))->toHaveCount(1)
        ->and(notificationsFor($quotation->lead->assignee, 'quotation_rejected'))->toHaveCount(1);
});

test('trigger: assigning a task notifies the field staff', function () {
    $staff = userWithRole('FIELD_STAFF');
    $pm = userWithRole('PM');
    $project = Project::factory()->create(['pm_id' => $pm->id]);

    app(TaskService::class)->create($project, [
        'title' => 'Pasang kabinet',
        'assignee_id' => $staff->id,
        'due_date' => now()->addDays(3)->toDateString(),
    ], $pm);

    expect(notificationsFor($staff, 'task_assigned'))->toHaveCount(1);
});

test('trigger: newly overdue tasks notify the project PM once', function () {
    $pm = userWithRole('PM');
    $project = Project::factory()->create(['pm_id' => $pm->id]);
    Task::factory()->count(2)->create(['project_id' => $project->id, 'due_date' => now()->subDays(2)]);

    $service = app(TaskService::class);

    expect($service->markOverdueTasks())->toBe(2)
        ->and($service->markOverdueTasks())->toBe(0)
        ->and(notificationsFor($pm, 'task_overdue'))->toHaveCount(1);
});

test('trigger: a penalty notifies the staff member and Finance', function () {
    $staff = userWithRole('FIELD_STAFF');
    $finance = userWithRole('FINANCE');
    Task::factory()->create(['assignee_id' => $staff->id, 'status' => TaskStatus::OnProgress->value]);

    app(PenaltyService::class)->runDailyCheck();

    expect(notificationsFor($staff, 'penalty_issued'))->toHaveCount(1)
        ->and(notificationsFor($finance, 'penalty_issued'))->toHaveCount(1);
});

test('trigger: the daily form reminder skips staff who already submitted and never repeats', function () {
    $late = userWithRole('FIELD_STAFF');
    $done = userWithRole('FIELD_STAFF');
    Task::factory()->create(['assignee_id' => $late->id]);
    $doneTask = Task::factory()->create(['assignee_id' => $done->id]);
    DailyTaskForm::factory()->create(['task_id' => $doneTask->id, 'staff_id' => $done->id, 'work_date' => now()->toDateString()]);

    $service = app(PenaltyService::class);

    expect($service->sendDailyFormReminders())->toBe(1)
        ->and($service->sendDailyFormReminders())->toBe(0)
        ->and(notificationsFor($late, 'daily_form_reminder'))->toHaveCount(1)
        ->and(notificationsFor($done))->toHaveCount(0);
});

test('trigger: marking a milestone done notifies the QA team, and again on resubmission', function () {
    $qa = userWithRole('QA');
    $milestone = Milestone::factory()->create(['status' => MilestoneStatus::InProgress->value]);

    app(MilestoneService::class)->markDone($milestone);
    $qaForm = $milestone->qaForm()->first();
    app(QaFormService::class)->review($qaForm, 'reject', $qaForm->checklist_data, 'Cat belum rata', userWithRole('QA'));
    app(MilestoneService::class)->markDone($milestone->fresh());

    expect(notificationsFor($qa, 'qa_form_created'))->toHaveCount(2)
        // Resubmission reopens the same form for review.
        ->and($qaForm->fresh()->status)->toBe(QaStatus::Pending)
        ->and($qaForm->fresh()->rejection_count)->toBe(1);
});

test('trigger: the overtime chain notifies PM, then staff and Finance, then staff', function () {
    $staff = userWithRole('FIELD_STAFF');
    $pm = userWithRole('PM');
    $finance = userWithRole('FINANCE');
    $project = Project::factory()->create(['pm_id' => $pm->id]);
    $service = app(OvertimeService::class);

    $overtime = $service->create([
        'project_id' => $project->id,
        'hours' => 2,
        'rate_per_hour' => 30000,
        'work_date' => now()->toDateString(),
        'reason' => 'Kejar target instalasi',
    ], $staff);
    expect(notificationsFor($pm, 'overtime_submitted'))->toHaveCount(1);

    $service->pmDecision($overtime, 'approve', $pm);
    expect(notificationsFor($staff, 'overtime_approved_pm'))->toHaveCount(1)
        ->and(notificationsFor($finance, 'overtime_approved_pm'))->toHaveCount(1);

    $service->financeDecision($overtime->fresh(), 'approve', $finance);
    expect(notificationsFor($staff, 'overtime_approved_finance'))->toHaveCount(1);
});

test('trigger: a rejected overtime tells the staff why', function () {
    $staff = userWithRole('FIELD_STAFF');
    $overtime = OvertimeRequest::factory()->create(['staff_id' => $staff->id]);

    app(OvertimeService::class)->pmDecision($overtime, 'reject', userWithRole('PM'), 'Tidak dikoordinasikan');

    expect(notificationsFor($staff, 'overtime_rejected')->first()->message)->toContain('Tidak dikoordinasikan');
});

test('trigger: an unpaid termin past its date goes OVERDUE and notifies Finance and CEO once', function () {
    $finance = userWithRole('FINANCE');
    $ceo = userWithRole('CEO');
    $late = Termin::factory()->create(['scheduled_date' => now()->subDays(2)]);
    $paid = Termin::factory()->create(['scheduled_date' => now()->subDays(2), 'status' => TerminStatus::Paid->value]);
    $upcoming = Termin::factory()->create(['scheduled_date' => now()->addDays(5)]);

    $service = app(TerminService::class);

    expect($service->markOverdue())->toBe(1)
        ->and($service->markOverdue())->toBe(0)
        ->and($late->fresh()->status)->toBe(TerminStatus::Overdue)
        ->and($paid->fresh()->status)->toBe(TerminStatus::Paid)
        ->and($upcoming->fresh()->status)->toBe(TerminStatus::Scheduled)
        ->and(notificationsFor($finance, 'termin_overdue'))->toHaveCount(1)
        ->and(notificationsFor($ceo, 'termin_overdue'))->toHaveCount(1);
});

test('an OVERDUE termin can still be marked paid', function () {
    $termin = Termin::factory()->create(['status' => TerminStatus::Overdue->value]);

    app(TerminService::class)->markPaid($termin, userWithRole('FINANCE'));

    expect($termin->fresh()->status)->toBe(TerminStatus::Paid);
});

test('the reminder jobs are idempotent across a same-day re-run', function () {
    Carbon::setTestNow(now()->setTime(20, 30));
    $staff = userWithRole('FIELD_STAFF');
    Task::factory()->create(['assignee_id' => $staff->id]);

    dispatch_sync(new DailyFormReminderJob);
    dispatch_sync(new DailyFormReminderJob);

    expect(notificationsFor($staff))->toHaveCount(1);
});
