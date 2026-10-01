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
 * employees here — they are paid per task (Upah Tukang).
 *
 * Deactivated (`is_active`), never deleted — the model refuses deletion so
 * salary history can't be orphaned (same rule as users, CLAUDE.md #7).
 */
class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'position',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function salaryPayments(): HasMany
    {
        return $this->hasMany(SalaryPayment::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
