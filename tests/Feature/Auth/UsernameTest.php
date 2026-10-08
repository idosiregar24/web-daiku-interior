<?php

use App\Enums\NotificationType;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use App\Support\Username;
use Database\Seeders\RoleSeeder;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function sprint21Ceo(): User
{
    return tap(User::factory()->create(), fn (User $user) => $user->assignRole('CEO'));
}

// Sub 01 — rules & suggestions

test('suggestions are built from the name and skip taken usernames', function () {
    expect(Username::suggestFor('Budi Santoso'))->toBe(['budisantoso', 'budis', 'budi']);

    User::factory()->create(['username' => 'budisantoso']);
    User::factory()->create(['username' => 'budi']);

    expect(Username::suggestFor('Budi Santoso'))->toBe(['budis', 'budi2', 'budi3'])
        ->and(Username::suggestFor('Ádi Śetiawan'))->toContain('adisetiawan')
        ->and(Username::suggestFor('Admin'))->not->toContain('admin');
});

test('an account needs a username or an email', function (array $identity, bool $ok) {
    $response = $this->actingAs(sprint21Ceo())->post(route('users.store'), [
        'name' => 'Budi',
        'password' => 'password123',
        'role' => 'FIELD_STAFF',
        ...$identity,
    ]);

    $ok ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors(['username', 'email']);
})->with([
    'username only' => [['username' => 'budi', 'email' => ''], true],
    'email only' => [['username' => '', 'email' => 'budi@example.com'], true],
    'both' => [['username' => 'budi', 'email' => 'budi@example.com'], true],
    'neither' => [['username' => '', 'email' => ''], false],
]);

test('the CEO creates a username-only account, lower-cased and audited', function () {
    $this->actingAs(sprint21Ceo())->post(route('users.store'), [
        'name' => 'Budi Tukang',
        'username' => 'BUDI',
        'email' => '',
        'password' => 'password123',
        'role' => 'FIELD_STAFF',
    ])->assertRedirect(route('users.index'));

    $user = User::where('username', 'budi')->first();

    expect($user)->not->toBeNull()
        ->and($user->email)->toBeNull()
        ->and($user->hasRole('FIELD_STAFF'))->toBeTrue()
        ->and(AuditLog::where('action', 'user.created')->where('model_id', $user->id)->first()->new_values['username'])->toBe('budi');
});

test('bad, reserved and taken usernames are rejected on save', function (string $username, string $message) {
    User::factory()->create(['username' => 'budi']);

    $this->actingAs(sprint21Ceo())->post(route('users.store'), [
        'name' => 'X', 'username' => $username, 'email' => '', 'password' => 'password123', 'role' => 'QA',
    ])->assertSessionHasErrors(['username' => $message]);
})->with([
    ['budi.s', 'Username hanya huruf kecil dan angka, diawali huruf, 3–30 karakter, tanpa spasi, titik atau garis bawah.'],
    ['budi_s', 'Username hanya huruf kecil dan angka, diawali huruf, 3–30 karakter, tanpa spasi, titik atau garis bawah.'],
    ['admin', 'Username ini tidak boleh dipakai.'],
    ['Budi', 'Username sudah dipakai.'],
]);

// Sub 02 — live check

test('the check answers each status', function (string $username, string $status) {
    User::factory()->create(['username' => 'budi']);

    $this->actingAs(sprint21Ceo())->getJson(route('username.check', ['username' => $username]))
        ->assertOk()
        ->assertJsonPath('status', $status);
})->with([
    ['andi', 'available'],
    ['budi', 'taken'],
    ['Budi', 'taken'],
    ['budi.s', 'invalid'],
    ['root', 'reserved'],
]);

test('taken answers carry suggestions from the name', function () {
    User::factory()->create(['username' => 'budi']);

    $this->actingAs(sprint21Ceo())->getJson(route('username.check', ['username' => 'budi', 'name' => 'Budi Santoso']))
        ->assertJsonPath('status', 'taken')
        ->assertJsonPath('suggestions.0', 'budisantoso');
});

test('your own username reads unchanged, and only the CEO may check for someone else', function () {
    $me = User::factory()->create(['username' => 'budi']);
    $other = User::factory()->create(['username' => 'andi']);

    $this->actingAs($me)->getJson(route('username.check', ['username' => 'budi']))->assertJsonPath('status', 'unchanged');
    // Someone else's user_id is ignored for a non-CEO user.
    $this->actingAs($me)->getJson(route('username.check', ['username' => 'andi', 'user_id' => $other->id]))->assertJsonPath('status', 'taken');
    $this->actingAs(sprint21Ceo())->getJson(route('username.check', ['username' => 'andi', 'user_id' => $other->id]))->assertJsonPath('status', 'unchanged');
});

test('guests cannot use the check and it is throttled', function () {
    $this->get(route('username.check', ['username' => 'budi']))->assertRedirect(route('login'));

    $user = User::factory()->create();

    foreach (range(1, 30) as $i) {
        $this->actingAs($user)->getJson(route('username.check', ['username' => "budi{$i}"]))->assertOk();
    }

    $this->actingAs($user)->getJson(route('username.check', ['username' => 'budi']))->assertStatus(429);
});

// Sub 03 — login

test('login works with email or username, any case', function (string $login) {
    User::factory()->create(['username' => 'budi', 'email' => 'budi@example.com']);

    $this->post('/login', ['login' => $login, 'password' => 'password']);

    $this->assertAuthenticated();
})->with(['budi@example.com', 'BUDI@example.com', 'budi', 'BUDI', ' budi ']);

test('an account without an email logs in with its username', function () {
    User::factory()->create(['username' => 'budi', 'email' => null]);

    $this->post('/login', ['login' => 'budi', 'password' => 'password']);

    $this->assertAuthenticated();
});

test('a failed login never says which part was wrong', function (string $login) {
    User::factory()->create(['username' => 'budi']);

    $this->post('/login', ['login' => $login, 'password' => 'wrong-password'])
        ->assertSessionHasErrors(['login' => 'Email/username atau password salah.']);

    $this->assertGuest();
})->with(['budi', 'nobody', 'nobody@example.com']);

test('a deactivated account is refused', function () {
    User::factory()->create(['username' => 'budi', 'is_active' => false]);

    $this->post('/login', ['login' => 'budi', 'password' => 'password'])
        ->assertSessionHasErrors(['login' => 'Email/username atau password salah.']);

    $this->assertGuest();
});

test('the login throttle counts per normalized identity', function () {
    User::factory()->create(['username' => 'budi']);

    foreach (['budi', 'BUDI', 'Budi', 'bUdi', 'budI'] as $login) {
        $this->post('/login', ['login' => $login, 'password' => 'wrong']);
    }

    $this->post('/login', ['login' => 'budi', 'password' => 'password'])
        ->assertSessionHasErrors('login');

    $this->assertGuest();
});

// Sub 04 — Profil Saya & Edit User

test('a user sets their first username, then waits 40 days to change it', function () {
    $user = User::factory()->create(['username' => null]);
    $patch = fn (string $username) => $this->actingAs($user)->patch('/profile', ['name' => $user->name, 'username' => $username, 'email' => $user->email]);

    $patch('budi')->assertSessionHasNoErrors();
    expect($user->fresh()->username)->toBe('budi');

    $this->travel(39)->days();
    $patch('budi2')->assertSessionHasErrors('username');
    expect($user->fresh()->username)->toBe('budi');

    $this->travel(2)->days();
    $patch('budi2')->assertSessionHasNoErrors();
    expect($user->fresh()->username)->toBe('budi2')
        ->and(AuditLog::where('action', 'user.identity_changed')->count())->toBe(2);
});

test('a user changes their email any time but cannot clear both', function () {
    $user = User::factory()->create(['username' => 'budi', 'username_changed_at' => now()]);

    $this->actingAs($user)->patch('/profile', ['name' => $user->name, 'username' => 'budi', 'email' => 'baru@example.com'])
        ->assertSessionHasNoErrors();
    expect($user->fresh()->email)->toBe('baru@example.com');

    $this->actingAs($user)->patch('/profile', ['name' => $user->name, 'username' => '', 'email' => ''])
        ->assertSessionHasErrors(['username', 'email']);
});

test('the CEO changes a username freely and the user is told', function () {
    $user = User::factory()->create(['username' => 'budi', 'username_changed_at' => now()->subDay()]);
    $user->assignRole('FIELD_STAFF');

    $this->actingAs(sprint21Ceo())->put(route('users.update', $user), [
        'name' => $user->name,
        'username' => 'budisantoso',
        'email' => $user->email,
        'role' => 'FIELD_STAFF',
        'is_active' => true,
    ])->assertRedirect(route('users.index'));

    $user->refresh();
    $notification = Notification::where('user_id', $user->id)->latest('id')->first();

    expect($user->username)->toBe('budisantoso')
        // The CEO's change doesn't restart the user's own countdown.
        ->and($user->username_changed_at->isYesterday())->toBeTrue()
        ->and($notification->type)->toBe(NotificationType::AccountUpdated->value)
        ->and($notification->message)->toContain('@budisantoso')
        ->and(AuditLog::where('action', 'user.updated')->where('model_id', $user->id)->first()->new_values)
        ->toMatchArray(['username' => 'budisantoso']);
});

test('a pre-rule username like the demo "pm" survives an unrelated save', function () {
    $user = User::factory()->create(['username' => 'pm']);

    $this->actingAs($user)->patch('/profile', ['name' => 'PM Baru', 'username' => 'pm', 'email' => $user->email])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->name)->toBe('PM Baru');
});

test('the users list finds people by username', function () {
    User::factory()->create(['name' => 'Budi', 'username' => 'budisantoso']);

    $this->actingAs(sprint21Ceo())->get(route('users.index', ['search' => '@budis']))
        ->assertInertia(fn ($page) => $page->where('users.total', 1)->where('users.data.0.username', 'budisantoso'));
});

// Sub 05 — accounts without an email

test('a CEO password reset forces the user to make their own', function () {
    $user = User::factory()->create(['username' => 'budi', 'email' => null]);
    $user->assignRole('FIELD_STAFF');

    $this->actingAs(sprint21Ceo())->put(route('users.update', $user), [
        'name' => $user->name, 'username' => 'budi', 'email' => '', 'password' => 'sementara123', 'role' => 'FIELD_STAFF', 'is_active' => true,
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->must_change_password)->toBeTrue();

    auth()->logout();
    $this->post('/login', ['login' => 'budi', 'password' => 'sementara123']);
    $this->assertAuthenticated();

    $this->get(route('dashboard'))->assertRedirect(route('password.change'));
    $this->get(route('password.change'))->assertOk();

    $this->put(route('password.update'), ['password' => 'punyabudi123', 'password_confirmation' => 'punyabudi123'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($user->fresh()->must_change_password)->toBeFalse();
    $this->get(route('profile.edit'))->assertOk();
});

test('the CEO resetting their own password is not forced to change it', function () {
    $ceo = sprint21Ceo();

    $this->actingAs($ceo)->put(route('users.update', $ceo), [
        'name' => $ceo->name, 'username' => $ceo->username, 'email' => $ceo->email, 'password' => 'baru12345', 'role' => 'CEO', 'is_active' => true,
    ])->assertSessionHasNoErrors();

    expect($ceo->fresh()->must_change_password)->toBeFalse();
});

test('an account without an email can confirm its password', function () {
    $user = User::factory()->create(['username' => 'budi', 'email' => null]);

    $this->actingAs($user)->post('/confirm-password', ['password' => 'password'])->assertSessionHasNoErrors();
    $this->actingAs($user)->post('/confirm-password', ['password' => 'wrong'])->assertSessionHasErrors('password');
});

test('forgot password answers the same whether or not the email exists', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->post(route('password.email'), ['email' => 'ada@example.com'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', fn (string $status) => str_contains($status, 'Minta CEO'));

    $this->post(route('password.email'), ['email' => 'tidakada@example.com'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', fn (string $status) => str_contains($status, 'Minta CEO'));
});
