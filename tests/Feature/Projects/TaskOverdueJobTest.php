<?php

use App\Enums\TaskStatus;
use App\Jobs\TaskOverdueJob;
use App\Models\Task;
use App\Services\TaskService;

test('TaskOverdueJob marks past-due, not-yet-DONE tasks as OVER', function () {
    $overdueTask = Task::factory()->create([
        'due_date' => now()->subDays(2)->toDateString(),
        'status' => TaskStatus::OnProgress->value,
    ]);
    $doneTask = Task::factory()->create([
        'due_date' => now()->subDays(2)->toDateString(),
        'status' => TaskStatus::Done->value,
    ]);
    $futureTask = Task::factory()->create([
        'due_date' => now()->addDays(2)->toDateString(),
        'status' => TaskStatus::OnProgress->value,
    ]);

    app(TaskOverdueJob::class)->handle(app(TaskService::class));

    expect($overdueTask->fresh()->status)->toBe(TaskStatus::Over)
        ->and($doneTask->fresh()->status)->toBe(TaskStatus::Done)
        ->and($futureTask->fresh()->status)->toBe(TaskStatus::OnProgress);
});

test('re-running the job does not touch a task already moved on to DONE', function () {
    $task = Task::factory()->create([
        'due_date' => now()->subDays(2)->toDateString(),
        'status' => TaskStatus::OnProgress->value,
    ]);

    app(TaskService::class)->markOverdueTasks();
    expect($task->fresh()->status)->toBe(TaskStatus::Over);

    $task->update(['status' => TaskStatus::Done->value]);

    app(TaskService::class)->markOverdueTasks();
    expect($task->fresh()->status)->toBe(TaskStatus::Done);
});
