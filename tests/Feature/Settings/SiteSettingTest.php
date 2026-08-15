<?php

use App\Models\SiteSetting;
use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(fn () => $this->seed(RoleSeeder::class));

test('CEO can view and update site settings', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');

    $this->actingAs($ceo)->get(route('settings.edit'))->assertOk();

    $this->actingAs($ceo)->put(route('settings.update'), [
        'site_name' => 'Daiku Interior Updated',
        'company_email' => 'info@daikuinterior.com',
    ])->assertRedirect();

    expect(SiteSetting::current()->site_name)->toBe('Daiku Interior Updated');
});

test('superadmin can view and update site settings', function () {
    $admin = User::factory()->create();
    $admin->assignRole('SUPERADMIN');

    $this->actingAs($admin)->get(route('settings.edit'))->assertOk();

    $this->actingAs($admin)->put(route('settings.update'), [
        'site_name' => 'Daiku Interior via SuperAdmin',
    ])->assertRedirect();

    expect(SiteSetting::current()->site_name)->toBe('Daiku Interior via SuperAdmin');
});

test('other roles are forbidden from site settings', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('settings.edit'))->assertForbidden();
})->with(['MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'QA', 'FINANCE', 'LOGISTICS', 'FIELD_STAFF']);

test('site settings is a singleton — repeated access reuses the same row', function () {
    $first = SiteSetting::current();
    $second = SiteSetting::current();

    expect($first->id)->toBe($second->id)
        ->and(SiteSetting::count())->toBe(1);
});

test('site name is required', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');

    $this->actingAs($ceo)->put(route('settings.update'), [
        'site_name' => '',
    ])->assertSessionHasErrors('site_name');
});
