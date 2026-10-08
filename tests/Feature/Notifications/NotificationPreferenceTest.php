<?php

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function preferenceUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

test('every role opens its own notification settings', function (string $role) {
    $this->actingAs(preferenceUser($role))->get(route('profile.notifications.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile/Notifications')
            ->where('preferences', ['muted_categories' => [], 'sound' => true])
            ->has('categories'));
})->with(['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'QA', 'FINANCE', 'LOGISTICS', 'FIELD_STAFF', 'HR', 'ASISTEN_PM', 'SUPERADMIN']);

test('the toggles match the role, plus anything actually received', function () {
    $staff = preferenceUser('FIELD_STAFF');

    $this->actingAs($staff)->get(route('profile.notifications.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('categories', fn ($categories) => collect($categories)->pluck('value')->all() === ['TASK', 'OVERTIME', 'LOGISTICS', 'ACCOUNT']));

    Notification::factory()->create(['user_id' => $staff->id, 'type' => NotificationType::QaRejected->value]);

    $this->actingAs($staff)->get(route('profile.notifications.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('categories', fn ($categories) => collect($categories)->pluck('value')->contains('QA')));
});

test('categories carrying "klien menunggu" are flagged', function () {
    $this->actingAs(preferenceUser('MARKETING'))->get(route('profile.notifications.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('categories', function ($categories) {
            $flags = collect($categories)->pluck('client_waiting', 'value');

            return $flags['FINANCE'] === true && $flags['PROJECT'] === true && $flags['HR'] === false;
        }));
});

test('preferences are saved for the current user only and shared with the app', function () {
    $user = preferenceUser('PM');
    $other = preferenceUser('PM');

    $this->actingAs($user)
        ->patch(route('profile.notifications.update'), ['muted_categories' => ['QA', 'LOGISTICS'], 'sound' => false])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($user->fresh()->mutedNotificationCategories())->toBe(['QA', 'LOGISTICS'])
        ->and($user->fresh()->wantsNotificationSound())->toBeFalse()
        ->and($other->fresh()->notification_preferences)->toBeNull();

    $this->actingAs($user)->get(route('notifications.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.notification_preferences.muted_categories', ['QA', 'LOGISTICS'])
            ->where('auth.user.notification_preferences.sound', false));
});

test('only known categories and a real yes/no are accepted', function (array $payload, string $field) {
    $user = preferenceUser('PM');

    $this->actingAs($user)->patch(route('profile.notifications.update'), $payload)->assertSessionHasErrors($field);

    expect($user->fresh()->notification_preferences)->toBeNull();
})->with([
    'kategori asing' => [['muted_categories' => ['GAJI_RAHASIA'], 'sound' => true], 'muted_categories.0'],
    'dobel' => [['muted_categories' => ['QA', 'QA'], 'sound' => true], 'muted_categories.0'],
    'tanpa sound' => [['muted_categories' => []], 'sound'],
]);

test('the device list shows only own devices', function () {
    $user = preferenceUser('CEO');
    PushSubscription::factory()->count(2)->create(['user_id' => $user->id]);
    PushSubscription::factory()->create();

    $this->actingAs($user)->get(route('profile.notifications.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('devices', 2)
            ->missing('devices.0.endpoint')
            ->missing('devices.0.auth_token'));
});

test('guests are sent to login', function () {
    $this->get(route('profile.notifications.edit'))->assertRedirect(route('login'));
    $this->patch(route('profile.notifications.update'), ['muted_categories' => [], 'sound' => true])->assertRedirect(route('login'));
});
