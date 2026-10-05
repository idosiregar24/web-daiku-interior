<?php

use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\User;
use App\Services\RoleRedirectService;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

test('every role opens Perlu Tindakan', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)
        ->get(route('inbox.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Inbox/Index')->has('groups'));
})->with(['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'ASISTEN_PM', 'QA', 'FINANCE', 'LOGISTICS', 'FIELD_STAFF', 'HR', 'SUPERADMIN']);

test('guests are sent to login', function () {
    $this->get(route('inbox.index'))->assertRedirect(route('login'));
});

test('the CEO lands on Perlu Tindakan after login', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');

    expect(app(RoleRedirectService::class)->routeNameFor($ceo))->toBe('inbox.index');
});

test('menu badges are shared with every page and summed per menu route', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');
    Quotation::factory()->count(2)->create(['status' => QuotationStatus::WaitingCeo->value]);

    $this->actingAs($ceo)
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('navBadges', ['quotations.index' => 2]));
});

test('a write by the user drops their cached badges, so the processed item is gone at once', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::WaitingCeo->value]);

    $this->actingAs($ceo)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('navBadges', ['quotations.index' => 1]));

    // Someone else's change is only picked up when the 60 s cache expires…
    $quotation->update(['status' => QuotationStatus::ReadyToSend->value]);
    $this->actingAs($ceo)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('navBadges', ['quotations.index' => 1]));

    // …but any write the user makes forgets it.
    $this->actingAs($ceo)->patchJson(route('profile.nav-preferences.update'), ['collapsed_groups' => []])->assertNoContent();
    $this->actingAs($ceo)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('navBadges', []));
});

test('the dashboard shows the three busiest queues', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');
    Quotation::factory()->create(['status' => QuotationStatus::WaitingCeo->value]);

    $this->actingAs($ceo)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('inbox', 1)
            ->where('inbox.0.key', 'quotation-ceo')
            ->where('inbox.0.count', 1));
});
