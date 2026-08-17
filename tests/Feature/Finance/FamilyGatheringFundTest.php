<?php

use App\Models\FamilyGatheringFund;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

test('CEO and FINANCE can view the family fund page', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('family-fund.index'))->assertOk();
})->with(['CEO', 'FINANCE']);

test('roles without access are forbidden from the family fund page', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('family-fund.index'))->assertForbidden();
})->with(['MARKETING', 'PM', 'FIELD_STAFF']);

test('family fund page computes the running balance correctly', function () {
    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');

    FamilyGatheringFund::factory()->create(['type' => 'INCOME', 'amount' => 50000]);
    FamilyGatheringFund::factory()->create(['type' => 'INCOME', 'amount' => 50000]);
    FamilyGatheringFund::factory()->create(['type' => 'EXPENSE', 'amount' => 30000]);

    $this->actingAs($finance)->get(route('family-fund.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('totalIncome', 100000)
            ->where('totalExpense', 30000)
            ->where('balance', 70000)
        );
});

test('finance can record a fund expense', function () {
    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');

    $this->actingAs($finance)->post(route('family-fund.recordExpense'), [
        'amount' => 200000,
        'description' => 'Acara gathering Q3',
    ])->assertRedirect();

    $entry = FamilyGatheringFund::where('type', 'EXPENSE')->first();
    expect($entry)->not->toBeNull()
        ->and((float) $entry->amount)->toBe(200000.0)
        ->and($entry->recorded_by)->toBe($finance->id);
});

test('roles other than FINANCE cannot record a fund expense', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');

    $this->actingAs($ceo)->post(route('family-fund.recordExpense'), [
        'amount' => 200000,
        'description' => 'Acara gathering Q3',
    ])->assertForbidden();
});
