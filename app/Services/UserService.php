<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function __construct(private AuditLogService $auditLogService) {}

    /**
     * Create a user and assign their single primary role — see
     * .claude/rules/database-standards.md §4 (roles live in Spatie's
     * pivot table, never a `users.role` column).
     */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            $user->assignRole($data['role']);

            // Granting a role is granting access — audited like the other
            // sensitive actions (PRD §9.4).
            $this->auditLogService->record('user.created', $user, null, [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $data['role'],
            ]);

            return $user;
        });
    }

    public function update(User $user, array $data): User
    {
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

        return DB::transaction(function () use ($user, $data) {
            $before = [
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'role' => $user->getRoleNames()->first(),
            ];

            $user->update([
                'name' => $data['name'],
                'email' => $data['email'],
                'is_active' => $data['is_active'] ?? $user->is_active,
            ]);

            $passwordChanged = ! empty($data['password']);

            if ($passwordChanged) {
                $user->update(['password' => Hash::make($data['password'])]);
            }

            $user->syncRoles([$data['role']]);

            $after = [
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'role' => $data['role'],
            ];

            // Only the fields that actually changed — the password itself
            // is never logged, only the fact it was reset.
            $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));

            if ($changed !== [] || $passwordChanged) {
                $this->auditLogService->record(
                    'user.updated',
                    $user,
                    array_intersect_key($before, array_flip($changed)),
                    array_intersect_key($after, array_flip($changed)) + ($passwordChanged ? ['password_reset' => true] : []),
                );
            }

            return $user;
        });
    }
}
