<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Models\Employee;
use App\Models\User;
use App\Support\Username;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function __construct(
        private AuditLogService $auditLogService,
        private NotificationService $notificationService,
    ) {}

    /**
     * Create a user and assign their single primary role — see
     * .claude/rules/database-standards.md §4 (roles live in Spatie's
     * pivot table, never a `users.role` column). Sprint 21: the account is
     * identified by a username, an email, or both (ValidatesUserIdentity).
     */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'username' => $data['username'] ?? null,
                'email' => $data['email'] ?? null,
                'password' => Hash::make($data['password']),
            ]);

            // A stacked role (Kepala Desain) brings its base role along.
            $user->assignRole(User::rolesFor($data['role']));

            // Granting a role is granting access — audited like the other
            // sensitive actions (PRD §9.4).
            $this->auditLogService->record('user.created', $user, null, [
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $data['role'],
            ]);

            return $user;
        });
    }

    /**
     * Edit User (CEO). Username/email changes here aren't held to the
     * 40-day limit and don't restart the user's own countdown (K4). A
     * password set here is temporary: the user must replace it on their
     * next visit (Sprint 21 Sub 05, EnsurePasswordChanged).
     */
    public function update(User $user, array $data, ?User $actor = null): User
    {
        $actor ??= auth()->user();

        // Attributes left to DB defaults (is_active) aren't on an instance
        // that was never re-read — start from the stored row.
        $user->refresh();

        // Sprint 10 decision #11: field staff are kept out of SDM, so an
        // account linked to an employee row can't become FIELD_STAFF until
        // HR unlinks it — otherwise the two data sets would mix silently.
        if ($data['role'] === 'FIELD_STAFF' && ! $user->hasRole('FIELD_STAFF')) {
            $employee = Employee::query()->where('user_id', $user->id)->first();

            if ($employee) {
                throw ValidationException::withMessages([
                    'role' => "Akun ini tertaut ke data karyawan {$employee->name} — lepas tautannya di menu SDM → Karyawan sebelum dijadikan Field Staff.",
                ]);
            }
        }

        return DB::transaction(function () use ($user, $data, $actor) {
            $before = [
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'role' => $user->assignableRoleName(),
            ];

            // A key left out means "unchanged"; the Form Request always sends both (null = cleared).
            $user->update([
                'name' => $data['name'],
                'username' => array_key_exists('username', $data) ? $data['username'] : $user->username,
                'email' => array_key_exists('email', $data) ? $data['email'] : $user->email,
                'is_active' => $data['is_active'] ?? $user->is_active,
            ]);

            $passwordChanged = ! empty($data['password']);
            $isSelf = $actor?->is($user) ?? false;

            if ($passwordChanged) {
                $user->update([
                    'password' => Hash::make($data['password']),
                    // The CEO resetting their own password needn't replace it again.
                    'must_change_password' => ! $isSelf,
                ]);
            }

            $user->syncRoles(User::rolesFor($data['role']));

            $after = [
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'role' => $data['role'],
            ];

            $changed = $this->changedKeys($before, $after);

            // Only the fields that actually changed — the password itself
            // is never logged, only the fact it was reset.
            if ($changed !== [] || $passwordChanged) {
                $this->auditLogService->record(
                    'user.updated',
                    $user,
                    array_intersect_key($before, array_flip($changed)),
                    array_intersect_key($after, array_flip($changed)) + ($passwordChanged ? ['password_reset' => true] : []),
                );
            }

            if (! $isSelf) {
                $this->notifyAccountChanged($user, $before, $after, $passwordChanged);
            }

            return $user;
        });
    }

    /**
     * Profil Saya — the user's own name, username and email (K1). The
     * email may change any time; the username once per
     * `daiku.username_change_days` (K4), the very first one always.
     */
    public function updateProfile(User $user, array $data): User
    {
        $user->refresh();

        $usernameChanged = ($data['username'] ?? null) !== $user->username;

        if ($usernameChanged && ($next = $user->usernameChangeableAt())) {
            throw ValidationException::withMessages([
                'username' => 'Username hanya bisa diganti sekali per '.config('daiku.username_change_days').' hari. Bisa diganti lagi pada '.$next->translatedFormat('j F Y').'.',
            ]);
        }

        return DB::transaction(function () use ($user, $data, $usernameChanged) {
            $before = $user->only(['name', 'username', 'email']);

            $user->fill([
                'name' => $data['name'],
                'username' => $data['username'] ?? null,
                'email' => $data['email'] ?? null,
            ]);

            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }

            if ($usernameChanged) {
                $user->username_changed_at = now();
            }

            $user->save();

            $after = $user->only(['name', 'username', 'email']);
            $changed = array_values(array_intersect($this->changedKeys($before, $after), ['username', 'email']));

            if ($changed !== []) {
                $this->auditLogService->record(
                    'user.identity_changed',
                    $user,
                    array_intersect_key($before, array_flip($changed)),
                    array_intersect_key($after, array_flip($changed)),
                    $user,
                );
            }

            return $user;
        });
    }

    /** The user replaced their password themselves — a CEO reset no longer applies. */
    public function changeOwnPassword(User $user, string $password): void
    {
        $user->update([
            'password' => Hash::make($password),
            'must_change_password' => false,
        ]);
    }

    /**
     * Sprint 21 Sub 02 — the live hint under UsernameInput. `$for` is the
     * account being edited (its own username reads "unchanged"); null for a
     * new account. `$name` seeds the suggestions.
     *
     * @return array{status: string, message: string, suggestions: list<string>}
     */
    public function checkUsername(string $username, ?User $for, ?string $name = null): array
    {
        $username = Username::normalize($username) ?? '';

        [$status, $message] = match (true) {
            $for !== null && $username !== '' && $username === $for->username => ['unchanged', 'Username Anda saat ini'],
            ! Username::isValid($username) => ['invalid', 'Hanya huruf kecil dan angka, tanpa spasi, titik atau garis bawah (3–30 karakter, diawali huruf)'],
            Username::isReserved($username) => ['reserved', 'Username ini tidak boleh dipakai'],
            User::query()->where('username', $username)->exists() => ['taken', 'Username sudah dipakai'],
            default => ['available', 'Username tersedia'],
        };

        $seed = trim((string) $name) !== '' ? (string) $name : $username;
        $suggestions = in_array($status, ['taken', 'reserved'], true) || ($status === 'invalid' && trim((string) $name) !== '')
            ? Username::suggestFor($seed, 3, $for?->id)
            : [];

        return ['status' => $status, 'message' => $message, 'suggestions' => $suggestions];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    private function changedKeys(array $before, array $after): array
    {
        return array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));
    }

    /**
     * Tell the user when the CEO changed how they log in — they would
     * otherwise find out at the login screen.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function notifyAccountChanged(User $user, array $before, array $after, bool $passwordChanged): void
    {
        $lines = [];

        if ($before['username'] !== $after['username']) {
            $lines[] = $after['username'] ? "Username Anda diubah menjadi @{$after['username']}." : 'Username Anda dihapus — login dengan email.';
        }

        if ($before['email'] !== $after['email']) {
            $lines[] = $after['email'] ? "Email Anda diubah menjadi {$after['email']}." : 'Email Anda dihapus — login dengan username.';
        }

        if ($passwordChanged) {
            $lines[] = 'Password Anda diatur ulang — buat password baru saat masuk.';
        }

        if ($lines !== []) {
            $this->notificationService->notify(
                $user,
                NotificationType::AccountUpdated,
                'Akun Anda diperbarui CEO',
                implode(' ', $lines),
            );
        }
    }
}
