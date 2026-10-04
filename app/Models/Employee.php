<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * PRD §4.7 "Gaji Karyawan Tetap" — a permanent, monthly-salaried employee
 * (Boy, Yola, Icha, ...). Separate from `users`: not every employee has a
 * system account; `user_id` optionally links one. Field staff are NOT
 * employees here — they are paid per task (Upah Tukang) and are kept out
 * of the SDM module entirely (Sprint 10 decision #11, see scopeHrEligible).
 *
 * Since Sprint 10 the job title is structured: `position_id` → positions
 * (→ divisions), never free text (decision #10). HR manages employees;
 * Finance reads them and pays salaries.
 *
 * Deactivated (`is_active`), never deleted — the model refuses deletion so
 * salary history can't be orphaned (same rule as users, CLAUDE.md #7).
 */
class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'position_id',
        'user_id',
        'base_salary',
        'bank_name',
        'account_no',
        'join_date',
        'is_active',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'base_salary' => 'decimal:2',
            'join_date' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Karyawan dinonaktifkan, tidak dihapus.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function salaryPayments(): HasMany
    {
        return $this->hasMany(SalaryPayment::class);
    }

    public function salaryChanges(): HasMany
    {
        return $this->hasMany(SalaryChange::class);
    }

    public function disciplinaryRecords(): HasMany
    {
        return $this->hasMany(DisciplinaryRecord::class);
    }

    public function kpiScores(): HasMany
    {
        return $this->hasMany(KpiScore::class);
    }

    public function performanceReviews(): HasMany
    {
        return $this->hasMany(PerformanceReview::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Sprint 10 decision #11: every SDM query goes through this scope.
     * Field staff can never be linked to an employee row (StoreEmployeeRequest,
     * UserService), so this is the defensive second layer — an employee
     * whose linked account somehow holds FIELD_STAFF never shows up in SDM.
     */
    public function scopeHrEligible(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('user_id')
            // Plain relation query, not Spatie's withoutRole() — that throws
            // when the role row doesn't exist yet (fresh installs, tests).
            ->orWhereHas('user', fn (Builder $user) => $user->whereDoesntHave('roles', fn (Builder $role) => $role->where('name', 'FIELD_STAFF'))));
    }

    /** Divisions/positions filter used by SDM lists (`?division=`/`?position=`). */
    public function scopeInStructure(Builder $query, ?int $divisionId, ?int $positionId): Builder
    {
        return $query
            ->when($positionId, fn (Builder $q) => $q->where('position_id', $positionId))
            ->when($divisionId && ! $positionId, fn (Builder $q) => $q->whereHas('position', fn (Builder $p) => $p->where('division_id', $divisionId)));
    }
}
