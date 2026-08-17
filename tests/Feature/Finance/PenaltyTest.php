<?php

use App\Enums\TaskStatus;
use App\Models\DailyTaskForm;
use App\Models\FamilyGatheringFund;
use App\Models\Penalty;
use App\Models\Task;
use App\Models\User;
use App\Services\PenaltyService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(fn () => $this->seed(RoleSeeder::class));

afterEach(fn () => Carbon::setTestNow());

test('penalty service penalizes a staff member with an active task who never submitted a daily form', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-17 21:00:00', 'Asia/Jakarta'));

    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');
    $staff = User::factory()->create();
    $staff->assignRole('FIELD_STAFF');
    Task::factory()->create(['assignee_id' => $staff->id, 'status' => TaskStatus::OnProgress->value]);

    $created = app(PenaltyService::class)->runDailyCheck();

    expect($created)->toBe(1)
        ->and(Penalty::where('staff_id', $staff->id)->where('type', 'DAILY_FORM_MISSING')->exists())->toBeTrue()
        ->and((float) Penalty::where('staff_id', $staff->id)->first()->amount)->toBe(50000.0)
        ->and(FamilyGatheringFund::where('type', 'INCOME')->count())->toBe(1);
});

test('penalty service skips staff who already submitted a daily form today', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-17 21:00:00', 'Asia/Jakarta'));

    $staff = User::factory()->create();
    $staff->assignRole('FIELD_STAFF');
    $task = Task::factory()->create(['assignee_id' => $staff->id, 'status' => TaskStatus::OnProgress->value]);
    DailyTaskForm::factory()->create([
        'task_id' => $task->id,
        'staff_id' => $staff->id,
        'work_date' => now('Asia/Jakarta')->toDateString(),
    ]);

    $created = app(PenaltyService::class)->runDailyCheck();

    expect($created)->toBe(0)
        ->and(Penalty::where('staff_id', $staff->id)->exists())->toBeFalse();
});

test('penalty service skips staff with no active tasks', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-17 21:00:00', 'Asia/Jakarta'));

    $staff = User::factory()->create();
    $staff->assignRole('FIELD_STAFF');
    Task::factory()->create(['assignee_id' => $staff->id, 'status' => TaskStatus::Done->value]);

    $created = app(PenaltyService::class)->runDailyCheck();

    expect($created)->toBe(0);
});

test('penalty service is idempotent — re-running the same day never doubles a penalty', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-17 21:00:00', 'Asia/Jakarta'));

    $staff = User::factory()->create();
    $staff->assignRole('FIELD_STAFF');
    Task::factory()->create(['assignee_id' => $staff->id, 'status' => TaskStatus::OnProgress->value]);

    app(PenaltyService::class)->runDailyCheck();
    $secondRun = app(PenaltyService::class)->runDailyCheck();

    expect($secondRun)->toBe(0)
        ->and(Penalty::where('staff_id', $staff->id)->count())->toBe(1);
});

test('field staff can only see their own penalties', function () {
    $staff = User::factory()->create();
    $staff->assignRole('FIELD_STAFF');
    $otherStaff = User::factory()->create();
    $otherStaff->assignRole('FIELD_STAFF');

    Penalty::factory()->create(['staff_id' => $staff->id]);
    Penalty::factory()->create(['staff_id' => $otherStaff->id]);

    $this->actingAs($staff)->get(route('penalties.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('penalties.data', 1)
            ->where('penalties.data.0.staff_id', $staff->id)
        );
});

test('roles with read access can view the penalty index', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('penalties.index'))->assertOk();
})->with(['CEO', 'PM', 'FINANCE', 'FIELD_STAFF']);

test('roles without access are forbidden from the penalty index', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('penalties.index'))->assertForbidden();
})->with(['MARKETING', 'DESIGNER', 'ESTIMATOR', 'QA', 'LOGISTICS']);
