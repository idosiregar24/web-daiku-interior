<?php

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use Database\Seeders\LeadSourceSeeder;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed([RoleSeeder::class, LeadSourceSeeder::class]));

test('marketing sees only their own due follow-ups plus the lead form options', function () {
    $marketing = User::factory()->create();
    $marketing->assignRole('MARKETING');
    $other = User::factory()->create();
    $other->assignRole('MARKETING');

    $own = Lead::factory()->followUpOn(now()->addDay()->toDateString())->create([
        'assigned_to' => $marketing->id,
        'status' => LeadStatus::FollowUp->value,
    ]);
    Lead::factory()->followUpOn(now()->addDay()->toDateString())->create([
        'assigned_to' => $other->id,
        'status' => LeadStatus::FollowUp->value,
    ]);

    $this->actingAs($marketing)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('followUps', 1)
            ->where('followUps.0.id', $own->id)
            ->has('marketers', 2)
            ->has('leadSources')
        );
});

test('roles that cannot write leads get no follow-ups or lead form options', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);
    Lead::factory()->followUpOn(now()->toDateString())->create([
        'status' => LeadStatus::FollowUp->value,
    ]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('followUps', 0)
            ->has('marketers', 0)
            ->has('leadSources', 0)
            ->has('leadCategories', 0)
        );
})->with(['DESIGNER', 'PM', 'FINANCE', 'FIELD_STAFF']);
