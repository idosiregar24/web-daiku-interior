<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One repayment of a StaffLoan — either cash returned by the staff, or an
 * installment deducted from a wage payment (`task_id` set). Append-only:
 * the table has `created_at` only.
 */
class StaffLoanPayment extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'staff_loan_id',
        'amount',
        'paid_date',
        'note',
        'task_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_date' => 'date:Y-m-d',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(StaffLoan::class, 'staff_loan_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
