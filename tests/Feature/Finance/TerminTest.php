<?php

use App\Enums\FinanceCategory;
use App\Enums\MilestoneStatus;
use App\Enums\TerminStatus;
use App\Models\FinanceTransaction;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Termin;
use App\Models\User;
use App\Services\TerminService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

test('roles with read access can view the Finance termin index', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('finance.termins.index'))->assertOk();
})->with(['CEO', 'FINANCE']);

test('PM cannot view the global Finance termin index but can schedule one on their project', function () {
    $pm = User::factory()->create();
    $pm->assignRole('PM');

    $this->actingAs($pm)->get(route('finance.termins.index'))->assertForbidden();

    $project = Project::factory()->create(['pm_id' => $pm->id, 'contract_value' => 100_000_000]);

    $this->actingAs($pm)->post(route('projects.termins.store', ['project' => $project->id]), [
        'percentage' => 30,
    ])->assertRedirect();

    expect(Termin::where('project_id', $project->id)->exists())->toBeTrue();
});

test('the termin index sends a calendar-month slice alongside the paginated list', function () {
    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');

    $inMonth = Termin::factory()->create(['scheduled_date' => '2026-08-22']);
    // Clearly outside the week-padded grid (August 2026's grid runs
    // 2026-07-27 through 2026-09-06 once padded to full Senin–Minggu weeks).
    $otherMonth = Termin::factory()->create(['scheduled_date' => '2026-09-15']);

    $this->actingAs($finance)
        ->get(route('finance.termins.index', ['month' => '2026-08']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('calendarMonth', '2026-08')
            ->has('calendarTermins', 1)
            ->where('calendarTermins.0.id', $inMonth->id)
        );

    expect(Termin::find($otherMonth->id))->not->toBeNull(); // sanity: the other-month row still exists, just excluded from the slice.
});

test('TerminService::getNextSaturday matches the PRD pseudocode', function () {
    $service = app(TerminService::class);

    // 2026-08-17 is a Monday.
    expect($service->getNextSaturday(Carbon::parse('2026-08-17'))->toDateString())->toBe('2026-08-22')
        // A Saturday itself rolls to the *next* Saturday (PRD: dayOfWeek===6 ? 7 : ...).
        ->and($service->getNextSaturday(Carbon::parse('2026-08-22'))->toDateString())->toBe('2026-08-29');
});

test('TerminService refuses to schedule termins totalling more than 100%', function () {
    $project = Project::factory()->create();
    Termin::factory()->create(['project_id' => $project->id, 'percentage' => 70]);

    expect(fn () => app(TerminService::class)->create($project, ['percentage' => 40]))
        ->toThrow(ValidationException::class);
});

test('marking a termin paid records a FinanceTransaction income row', function () {
    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');
    $project = Project::factory()->create();
    $termin = Termin::factory()->create([
        'project_id' => $project->id,
        'milestone_id' => null,
        'termin_number' => 1,
        'amount' => 30_000_000,
    ]);

    $this->actingAs($finance)->post(route('finance.termins.markPaid', ['termin' => $termin->id]))->assertRedirect();

    $termin->refresh();
    expect($termin->status)->toBe(TerminStatus::Paid)
        ->and($termin->paid_at)->not->toBeNull();

    $transaction = FinanceTransaction::where('reference_id', $termin->id)->first();
    expect($transaction)->not->toBeNull()
        ->and($transaction->kategori)->toBe(FinanceCategory::DownPayment)
        ->and((float) $transaction->amount)->toBe(30_000_000.0);
});

test('CEO and PM cannot mark a termin paid', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);
    $termin = Termin::factory()->create();

    $this->actingAs($user)->post(route('finance.termins.markPaid', ['termin' => $termin->id]))->assertForbidden();
})->with(['CEO', 'PM']);

test('a termin tied to a milestone stays locked until that milestone is COMPLETED', function () {
    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');
    $milestone = Milestone::factory()->create(['status' => MilestoneStatus::QaWaiting->value]);
    $termin = Termin::factory()->create(['project_id' => $milestone->project_id, 'milestone_id' => $milestone->id]);

    $this->actingAs($finance)->post(route('finance.termins.markPaid', ['termin' => $termin->id]))
        ->assertSessionHasErrors('status');

    expect($termin->fresh()->status)->toBe(TerminStatus::Scheduled);
});
