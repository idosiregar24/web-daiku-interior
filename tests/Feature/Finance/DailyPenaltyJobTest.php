<?php

use App\Enums\TaskStatus;
use App\Jobs\DailyPenaltyJob;
use App\Models\Penalty;
use App\Models\Task;
use App\Models\User;
use App\Services\PenaltyService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(fn () => $this->seed(RoleSeeder::class));

afterEach(fn () => Carbon::setTestNow());

test('DailyPenaltyJob runs PenaltyService::runDailyCheck() via the container', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-17 21:00:00', 'Asia/Jakarta'));

    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');
    $staff = User::factory()->create();
    $staff->assignRole('FIELD_STAFF');
    Task::factory()->create(['assignee_id' => $staff->id, 'status' => TaskStatus::OnProgress->value]);

    app(DailyPenaltyJob::class)->handle(app(PenaltyService::class));

    expect(Penalty::where('staff_id', $staff->id)->exists())->toBeTrue();
});
