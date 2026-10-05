<?php

use App\Enums\TaskStatus;
use App\Models\DailyTaskForm;
use App\Models\Task;
use App\Models\User;
use App\Services\RoleRedirectService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 13 H2/H3/H11 — the Tukang's "Hari Ini" screen and its one-tap
 * save (status + daily form) through the existing endpoints.
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->tukang = User::factory()->create();
    $this->tukang->assignRole('FIELD_STAFF');
});

afterEach(fn () => Carbon::setTestNow());

function monday(string $time = '10:00'): void
{
    Carbon::setTestNow(Carbon::parse("2026-10-05 {$time}", 'Asia/Jakarta'));
}

test('a Tukang lands on Hari Ini', function () {
    expect(app(RoleRedirectService::class)->routeNameFor($this->tukang))->toBe('today.index');
});

test("Hari Ini lists the Tukang's own open tasks with today's form mark", function () {
    monday();
    $withForm = Task::factory()->create(['assignee_id' => $this->tukang->id, 'status' => TaskStatus::OnProgress->value]);
    $withoutForm = Task::factory()->create(['assignee_id' => $this->tukang->id]);
    Task::factory()->create(['assignee_id' => $this->tukang->id, 'status' => TaskStatus::Done->value]);
    Task::factory()->create(); // someone else's
    DailyTaskForm::factory()->create(['task_id' => $withForm->id, 'staff_id' => $this->tukang->id]);

    $this->actingAs($this->tukang)
        ->get(route('today.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Today/Index')
            ->where('isWorkDay', true)
            ->where('penaltyAt', '21:00')
            ->where('canSubmitDailyForm', true)
            ->has('tasks', 2)
            ->where('tasks', fn ($tasks) => collect($tasks)->pluck('has_form_today', 'id')->sortKeys()->all() === [
                $withForm->id => true,
                $withoutForm->id => false,
            ])
            ->where('upcoming', []));
});

test('after the penalty hour the form can no longer be sent from Hari Ini', function () {
    monday('21:15');
    Task::factory()->create(['assignee_id' => $this->tukang->id]);

    $this->actingAs($this->tukang)
        ->get(route('today.index'))
        ->assertInertia(fn (Assert $page) => $page->where('canSubmitDailyForm', false)->has('tasks', 1));
});

test("on Sunday Hari Ini shows next week's tasks instead, without the form warning", function () {
    Carbon::setTestNow(Carbon::parse('2026-10-04 09:00', 'Asia/Jakarta')); // Sunday
    $monday = Task::factory()->create(['assignee_id' => $this->tukang->id, 'due_date' => '2026-10-05']);
    $saturday = Task::factory()->create(['assignee_id' => $this->tukang->id, 'due_date' => '2026-10-10']);
    Task::factory()->create(['assignee_id' => $this->tukang->id, 'due_date' => '2026-10-12']); // the week after
    Task::factory()->create(['assignee_id' => $this->tukang->id, 'due_date' => '2026-10-06', 'status' => TaskStatus::Done->value]);

    $this->actingAs($this->tukang)
        ->get(route('today.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('isWorkDay', false)
            ->where('canSubmitDailyForm', false)
            ->where('tasks', [])
            ->has('upcoming', 2)
            ->where('upcoming.0.id', $monday->id)
            ->where('upcoming.1.id', $saturday->id));
});

test('the sheet save records status and daily form once', function () {
    monday();
    $task = Task::factory()->create(['assignee_id' => $this->tukang->id]);

    $this->actingAs($this->tukang)
        ->from(route('today.index'))
        ->post(route('daily-forms.store', $task), ['status' => 'ONPROGRESS', 'kendala' => 'Bahan telat', 'notes' => 'Lanjut besok'])
        ->assertRedirect(route('today.index'))
        ->assertSessionHasNoErrors();

    expect($task->fresh()->status)->toBe(TaskStatus::OnProgress)
        ->and($task->fresh()->kendala)->toBe('Bahan telat')
        ->and(DailyTaskForm::where('task_id', $task->id)->count())->toBe(1);

    // A second save the same day changes the status only (the sheet switches
    // to tasks.updateStatus once has_form_today is true)…
    $this->actingAs($this->tukang)
        ->patch(route('tasks.updateStatus', $task), ['status' => 'DONE'])
        ->assertSessionHasNoErrors();

    // …and a second form is refused, so the form stays recorded once.
    $this->actingAs($this->tukang)
        ->post(route('daily-forms.store', $task), ['status' => 'DONE'])
        ->assertSessionHasErrors('task_id');

    expect($task->fresh()->status)->toBe(TaskStatus::Done)
        ->and(DailyTaskForm::where('task_id', $task->id)->count())->toBe(1);
});

test('the title and due date never change from the Tukang side', function () {
    monday();
    $task = Task::factory()->create(['assignee_id' => $this->tukang->id, 'title' => 'Pasang HPL', 'due_date' => '2026-10-09']);

    $this->actingAs($this->tukang)
        ->patch(route('tasks.updateStatus', $task), ['status' => 'ONPROGRESS', 'title' => 'Diubah', 'due_date' => '2026-12-31'])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->tukang)
        ->put(route('tasks.update', $task), ['title' => 'Diubah'])
        ->assertForbidden();

    expect($task->fresh()->title)->toBe('Pasang HPL')
        ->and($task->fresh()->due_date->toDateString())->toBe('2026-10-09');
});

test("another Tukang's task can't be saved from the sheet", function () {
    monday();
    $task = Task::factory()->create(['status' => TaskStatus::Pending->value]);

    $this->actingAs($this->tukang)
        ->patch(route('tasks.updateStatus', $task), ['status' => 'DONE'])
        ->assertForbidden();

    $this->actingAs($this->tukang)
        ->post(route('daily-forms.store', $task), ['status' => 'DONE'])
        ->assertSessionHasErrors('task_id');

    expect($task->fresh()->status)->toBe(TaskStatus::Pending)
        ->and(DailyTaskForm::count())->toBe(0);
});

test('the task list marks today\'s form for a Tukang', function () {
    monday();
    $task = Task::factory()->create(['assignee_id' => $this->tukang->id]);

    $this->actingAs($this->tukang)
        ->get(route('tasks.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canSubmitDailyForm', true)
            ->where('tasks.data.0.id', $task->id)
            ->where('tasks.data.0.has_form_today', false));
});

test('Hari Ini is for the Tukang only', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('today.index'))->assertForbidden();
})->with(['CEO', 'PM', 'FINANCE', 'QA']);

test('guests are sent to login', function () {
    $this->get(route('today.index'))->assertRedirect(route('login'));
});
