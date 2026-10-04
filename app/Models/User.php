<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /** Tasks assigned to this user (Field Staff) — used by PenaltyService's "punya task aktif" check. */
    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_id');
    }

    /** Daily forms this Field Staff submitted — PenaltyService's "sudah isi hari ini" check. */
    public function dailyTaskForms(): HasMany
    {
        return $this->hasMany(DailyTaskForm::class, 'staff_id');
    }

    /**
     * Sprint 12 decision #15 — a role stacked on top of a base role: a
     * Kepala Desain is a DESIGNER too, so every `role:DESIGNER` route and
     * check keeps working for them. stacked role => base role.
     */
    public const STACKED_ROLES = [
        'KEPALA_DESAIN' => 'DESIGNER',
    ];

    /**
     * The Spatie roles to give a user picked as `$role` in User Management
     * — the role plus its base role when it's a stacked one.
     *
     * @return list<string>
     */
    public static function rolesFor(string $role): array
    {
        return isset(self::STACKED_ROLES[$role]) ? [self::STACKED_ROLES[$role], $role] : [$role];
    }

    /**
     * The role the UI gates on (nav, landing page) — the base role, never
     * a stacked one, so a Kepala Desain gets everything a Designer gets.
     */
    public function primaryRoleName(): ?string
    {
        $names = $this->getRoleNames();

        return $names->first(fn (string $name) => ! isset(self::STACKED_ROLES[$name])) ?? $names->first();
    }

    /** The role User Management shows and edits — the stacked one when present. */
    public function assignableRoleName(): ?string
    {
        $names = $this->getRoleNames();

        return $names->first(fn (string $name) => isset(self::STACKED_ROLES[$name])) ?? $this->primaryRoleName();
    }
}
