<?php

use App\Models\Penalty;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 13 H1/H10 — the Tukang's "Lainnya" page: this month's penalty
 * total, own penalties only.
 */

beforeEach(fn () => $this->seed(RoleSeeder::class));

afterEach(fn () => Carbon::setTestNow());

function moreUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

test("a Tukang sees this month's total of their own penalties only", function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 09:00', 'Asia/Jakarta'));
    $tukang = moreUser('FIELD_STAFF');

    Penalty::factory()->create(['staff_id' => $tukang->id, 'date_occurred' => '2026-10-02']);
    Penalty::factory()->paid()->create(['staff_id' => $tukang->id, 'date_occurred' => '2026-10-10']);
    // Last month, and someone else's — neither counts.
    Penalty::factory()->create(['staff_id' => $tukang->id, 'date_occurred' => '2026-09-30']);
    Penalty::factory()->create(['date_occurred' => '2026-10-05']);

    $this->actingAs($tukang)
        ->get(route('more.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('More/Index')
            ->where('penaltyThisMonth.count', 2)
            ->where('penaltyThisMonth.total', 100000)
            ->where('penaltyThisMonth.unpaid', 50000)
            ->where('penaltyThisMonth.month', 'Oktober 2026'));
});

test('a Tukang without penalties sees zero', function () {
    $this->actingAs(moreUser('FIELD_STAFF'))
        ->get(route('more.index'))
        ->assertInertia(fn (Assert $page) => $page->where('penaltyThisMonth.count', 0)->where('penaltyThisMonth.total', 0));
});

test('other roles get 403', function (string $role) {
    $this->actingAs(moreUser($role))->get(route('more.index'))->assertForbidden();
})->with(['CEO', 'PM', 'FINANCE', 'MARKETING', 'QA', 'LOGISTICS', 'HR']);

test('guests are sent to login', function () {
    $this->get(route('more.index'))->assertRedirect(route('login'));
});
