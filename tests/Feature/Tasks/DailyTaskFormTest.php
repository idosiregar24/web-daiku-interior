<?php

use App\Enums\TaskStatus;
use App\Models\DailyTaskForm;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(fn () => $this->seed(RoleSeeder::class));

afterEach(fn () => Carbon::setTestNow());

test('field staff can submit a daily form for their own active task before 21:00 WIB', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-17 20:00:00', 'Asia/Jakarta'));

    $staff = User::factory()->create();
    $staff->assignRole('FIELD_STAFF');
    $task = Task::factory()->create(['assignee_id' => $staff->id, 'status' => TaskStatus::Pending->value]);

    $this->actingAs($staff)->post(route('daily-forms.store', ['task' => $task->id]), [
        'status' => 'ONPROGRESS',
        'kendala' => 'Hujan deras',
    ])->assertRedirect();

    expect(DailyTaskForm::where('task_id', $task->id)->exists())->toBeTrue()
        ->and($task->fresh()->status)->toBe(TaskStatus::OnProgress)
        ->and($task->fresh()->kendala)->toBe('Hujan deras');
});

test('daily form submission is rejected after 21:00 WIB', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-17 21:30:00', 'Asia/Jakarta'));

    $staff = User::factory()->create();
    $staff->assignRole('FIELD_STAFF');
    $task = Task::factory()->create(['assignee_id' => $staff->id, 'status' => TaskStatus::Pending->value]);

    $this->actingAs($staff)->post(route('daily-forms.store', ['task' => $task->id]), [
        'status' => 'ONPROGRESS',
    ])->assertSessionHasErrors('work_date');

    expect(DailyTaskForm::where('task_id', $task->id)->exists())->toBeFalse();
});

test('a task cannot get two daily forms on the same day', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Jakarta'));

    $staff = User::factory()->create();
    $staff->assignRole('FIELD_STAFF');
    $task = Task::factory()->create(['assignee_id' => $staff->id, 'status' => TaskStatus::Pending->value]);

    DailyTaskForm::factory()->create([
        'task_id' => $task->id,
        'staff_id' => $staff->id,
        'work_date' => now('Asia/Jakarta')->toDateString(),
    ]);

    $this->actingAs($staff)->post(route('daily-forms.store', ['task' => $task->id]), [
        'status' => 'ONPROGRESS',
    ])->assertSessionHasErrors('task_id');
});

test('field staff cannot submit a daily form for a task that is not theirs', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Jakarta'));

    $staff = User::factory()->create();
    $staff->assignRole('FIELD_STAFF');
    $task = Task::factory()->create(['status' => TaskStatus::Pending->value]);

    $this->actingAs($staff)->post(route('daily-forms.store', ['task' => $task->id]), [
        'status' => 'ONPROGRESS',
    ])->assertSessionHasErrors('task_id');
});

test('roles with read access can view the daily form index', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('daily-forms.index'))->assertOk();
})->with(['CEO', 'PM', 'FIELD_STAFF']);

test('roles without access are forbidden from the daily form index', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('daily-forms.index'))->assertForbidden();
})->with(['MARKETING', 'DESIGNER', 'ESTIMATOR', 'QA', 'FINANCE', 'LOGISTICS']);
