<?php

use App\Models\Design;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(fn () => $this->seed(RoleSeeder::class));

// security-standards.md §3: "throttle:60,1 ... task status update, daily form submit".
test('task status updates are rate limited to 60 per minute per user', function () {
    $staff = User::factory()->create();
    $staff->assignRole('FIELD_STAFF');
    $task = Task::factory()->create(['assignee_id' => $staff->id]);

    for ($i = 0; $i < 60; $i++) {
        $this->actingAs($staff)->patch(route('tasks.updateStatus', $task), ['status' => 'ONPROGRESS'])->assertRedirect();
    }

    $this->actingAs($staff)->patch(route('tasks.updateStatus', $task), ['status' => 'ONPROGRESS'])->assertStatus(429);
});

test('user-supplied links only accept http(s) schemes', function (string $url) {
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $project = Project::factory()->create(['pm_id' => $pm->id]);

    $this->actingAs($pm)->post(route('progress-logs.store', $project), [
        'percentage' => 10,
        'description' => 'Progres',
        'log_date' => now()->toDateString(),
        'ref_urls' => [$url],
    ])->assertSessionHasErrors('ref_urls.0');
})->with([
    'javascript' => ['javascript:alert(document.cookie)'],
    'data' => ['data:text/html,<script>alert(1)</script>'],
    'ftp' => ['ftp://example.com/file'],
]);

test('design links reject non-http schemes', function () {
    $designer = User::factory()->create();
    $designer->assignRole('DESIGNER');
    $design = Design::factory()->create();

    $this->actingAs($designer)->put(route('design.update', $design), [
        'design_urls' => ['javascript:alert(1)'],
    ])->assertSessionHasErrors('design_urls.0');
});
