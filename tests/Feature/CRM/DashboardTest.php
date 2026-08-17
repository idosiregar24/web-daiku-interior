<?php

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

test('CEO and MARKETING can view the pipeline dashboard', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('crm.dashboard'))->assertOk();
})->with(['CEO', 'MARKETING']);

test('roles without access are forbidden from the pipeline dashboard', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('crm.dashboard'))->assertForbidden();
})->with(['DESIGNER', 'ESTIMATOR', 'PM', 'QA', 'FINANCE', 'LOGISTICS', 'FIELD_STAFF']);

test('dashboard computes funnel counts and conversion rate correctly', function () {
    $marketing = User::factory()->create();
    $marketing->assignRole('MARKETING');

    Lead::factory()->count(3)->create(['status' => LeadStatus::FollowUp->value]);
    Lead::factory()->count(2)->create(['status' => LeadStatus::DealDesain->value]);
    Lead::factory()->count(1)->create(['status' => LeadStatus::Closing->value]);
    Lead::factory()->count(4)->create(['status' => LeadStatus::Lost->value]);

    $this->actingAs($marketing)->get(route('crm.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.total', 10)
            ->where('stats.closing', 1)
            ->where('stats.lost', 4)
            ->where('stats.conversionRate', 10)
            ->has('funnel', 3)
        );
});
