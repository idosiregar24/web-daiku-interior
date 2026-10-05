<?php

use App\Models\Employee;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;

/*
 * Sprint 13 #12 / D5 — the topbar search finds only what the user's list
 * pages would show them, and answers with a field whitelist.
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $lead = Lead::factory()->create(['client_name' => 'Ibu Sinta Wijaya']);
    $this->mine = Project::factory()->create(['name' => 'Kitchen Set Sinta', 'lead_id' => $lead->id]);
    $this->other = Project::factory()->create(['name' => 'Kitchen Set Budi']);
    Quotation::factory()->create(['lead_id' => $lead->id, 'total_amount' => 98_765_000]);
    Employee::factory()->create(['name' => 'Sinta Karyawati']);
});

function searcher(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** @return array<string, list<string>> kind => labels */
function searchFor(User $user, string $q): array
{
    $response = test()->actingAs($user)->getJson(route('search', ['q' => $q]))->assertOk();

    return collect($response->json('groups'))->mapWithKeys(fn (array $group) => [
        $group['key'] => collect($group['items'])->pluck('label')->all(),
    ])->all();
}

test('the CEO finds projects, leads, quotations and employees', function () {
    $found = searchFor(searcher('CEO'), 'sinta');

    expect(array_keys($found))->toBe(['projects', 'leads', 'quotations', 'employees'])
        ->and($found['projects'])->toBe(['Kitchen Set Sinta'])
        ->and($found['employees'])->toBe(['Sinta Karyawati']);
});

test('a project is found by its client name too', function () {
    expect(searchFor(searcher('PM'), 'Wijaya')['projects'])->toBe(['Kitchen Set Sinta']);
});

test('an Asisten PM finds only the projects assigned to them', function () {
    $assistant = searcher('ASISTEN_PM');
    $this->mine->update(['assistant_pm_id' => $assistant->id]);

    $found = searchFor($assistant, 'kitchen');

    expect($found['projects'])->toBe(['Kitchen Set Sinta'])
        ->and($found)->not->toHaveKey('leads')
        ->and($found)->not->toHaveKey('employees');
});

test('a Tukang finds only the projects they have a task on, and nothing else', function () {
    $tukang = searcher('FIELD_STAFF');
    Task::factory()->create(['project_id' => $this->other->id, 'assignee_id' => $tukang->id]);

    expect(searchFor($tukang, 'kitchen'))->toBe(['projects' => ['Kitchen Set Budi']]);
});

test('HR finds employees but no sales data', function () {
    expect(searchFor(searcher('HR'), 'sinta'))->toBe(['employees' => ['Sinta Karyawati']]);
});

test('the response is a whitelist — no raw model fields, no amounts', function () {
    $response = $this->actingAs(searcher('MARKETING'))->getJson(route('search', ['q' => 'sinta']))->assertOk();

    foreach ($response->json('groups') as $group) {
        foreach ($group['items'] as $item) {
            expect(array_keys($item))->toBe(['id', 'label', 'sublabel', 'url']);
        }
    }

    expect($response->getContent())->not->toContain('98765000')
        ->and($response->json('groups.*.key'))->not->toContain('employees');
});

test('the query needs at least 2 characters', function () {
    $this->actingAs(searcher('CEO'))->getJson(route('search', ['q' => 'a']))->assertJsonValidationErrors('q');
});

test('guests are sent to login', function () {
    $this->get(route('search', ['q' => 'sinta']))->assertRedirect(route('login'));
});
