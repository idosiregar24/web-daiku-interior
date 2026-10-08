<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Username;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
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
        'username',
        'username_changed_at',
        'email',
        'password',
        'must_change_password',
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
            'username_changed_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
            'nav_preferences' => 'array',
            'notification_preferences' => 'array',
        ];
    }

    /**
     * Sprint 21 — stored lower-case whatever the caller passed (Form
     * Requests already normalize; this is the second layer). Empty → null,
     * so a cleared field never collides on the UNIQUE index.
     */
    protected function username(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => Username::normalize($value));
    }

    protected function email(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => ($value = mb_strtolower(trim((string) $value))) === '' ? null : $value);
    }

    /** How the account is named on screen and in logs: "@budi", else the email. */
    public function loginLabel(): string
    {
        return $this->username ? '@'.$this->username : (string) $this->email;
    }

    /**
     * Sprint 21 (K4) — the first moment the user may change their own
     * username again; null when they may change it now (never changed it
     * themselves, or the wait is over). Clearing it counts as a change too,
     * so clear-and-refill can't skip the wait. The CEO is never held to this.
     */
    public function usernameChangeableAt(): ?Carbon
    {
        if (! $this->username_changed_at) {
            return null;
        }

        $next = $this->username_changed_at->copy()->addDays((int) config('daiku.username_change_days'));

        return $next->isFuture() ? $next : null;
    }

    /**
     * Sprint 18 Sub 05 — categories whose device push this user switched
     * off (Pengaturan Notifikasi). The bell still records them; a P1 in a
     * muted category still arrives, only silently (WebPushService).
     *
     * @return list<string>
     */
    public function mutedNotificationCategories(): array
    {
        return array_values($this->notification_preferences['muted_categories'] ?? []);
    }

    /** Chime in the open app for P1/P2 (default on). */
    public function wantsNotificationSound(): bool
    {
        return (bool) ($this->notification_preferences['sound'] ?? true);
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

    /** Sprint 18 — devices that receive this user's Web Push notifications. */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
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
