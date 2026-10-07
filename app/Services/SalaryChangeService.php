<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\SalaryChangeStatus;
use App\Models\Employee;
use App\Models\PerformanceReview;
use App\Models\SalaryChange;
use App\Models\SalaryPayment;
use App\Models\StaffLoan;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SDM (Sprint 10, decision #3, §3.2) — base-salary changes and salary recaps.
 *
 * HR requests (old → new, effective date, reason), the CEO approves or
 * rejects once. On approval `employees.base_salary` changes in the same
 * transaction when the effective date has arrived; a future-dated change
 * waits (APPROVED, `applied_at` null) until applyDue() — the daily
 * ApplyDueSalaryChangesJob — applies it. Nothing here touches salary
 * payments: Finance still pays (decision #8 — no automatic pay effect).
 */
class SalaryChangeService
{
    public function __construct(
        private AuditLogService $auditLogService,
        private NotificationService $notificationService,
    ) {}

    /**
     * @param  array{new_salary: numeric-string|float|int, effective_date: string, reason: string, performance_review_id?: int|string|null}  $data
     */
    public function request(Employee $employee, array $data, User $actor): SalaryChange
    {
        return DB::transaction(function () use ($employee, $data, $actor) {
            // Serializes two "Ajukan" clicks so only one PENDING row can exist.
            $locked = Employee::query()->hrEligible()->lockForUpdate()->find($employee->id);

            if (! $locked) {
                throw ValidationException::withMessages(['employee_id' => 'Karyawan tidak ditemukan di modul SDM.']);
            }

            if (! $locked->is_active) {
                throw ValidationException::withMessages([
                    'employee_id' => "{$locked->name} sudah nonaktif — perubahan gaji tidak bisa diajukan.",
                ]);
            }

            if (SalaryChange::query()->where('employee_id', $locked->id)->pending()->exists()) {
                throw ValidationException::withMessages([
                    'employee_id' => "{$locked->name} masih punya pengajuan perubahan gaji yang menunggu keputusan CEO.",
                ]);
            }

            if ($this->scheduledQuery()->where('employee_id', $locked->id)->exists()) {
                throw ValidationException::withMessages([
                    'employee_id' => "{$locked->name} masih punya perubahan gaji yang sudah disetujui dan menunggu tanggal berlakunya.",
                ]);
            }

            $newCents = self::cents($data['new_salary']);
            $oldCents = self::cents($locked->base_salary);

            if ($newCents <= 0) {
                throw ValidationException::withMessages(['new_salary' => 'Gaji pokok baru harus lebih dari 0.']);
            }

            if ($newCents === $oldCents) {
                throw ValidationException::withMessages([
                    'new_salary' => 'Gaji pokok baru sama dengan gaji pokok saat ini ('.self::rupiah($oldCents).').',
                ]);
            }

            $reviewId = filled($data['performance_review_id'] ?? null) ? (int) $data['performance_review_id'] : null;

            if ($reviewId && ! PerformanceReview::query()->whereKey($reviewId)->where('employee_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['performance_review_id' => 'Evaluasi yang dirujuk bukan milik karyawan ini.']);
            }

            $change = SalaryChange::create([
                'employee_id' => $locked->id,
                'old_salary' => self::decimal($oldCents),
                'new_salary' => self::decimal($newCents),
                'effective_date' => Carbon::parse($data['effective_date'])->toDateString(),
                'reason' => $data['reason'],
                'status' => SalaryChangeStatus::Pending,
                'performance_review_id' => $reviewId,
                'requested_by' => $actor->id,
            ]);

            $this->auditLogService->record('hr.salary_change_requested', $change, null, [
                'employee_id' => $locked->id,
                'employee_name' => $locked->name,
                'old_salary' => $change->old_salary,
                'new_salary' => $change->new_salary,
                'effective_date' => $change->effective_date->toDateString(),
                'reason' => $change->reason,
                'performance_review_id' => $reviewId,
            ], $actor);

            $this->notificationService->notifyRoles(
                ['CEO'],
                NotificationType::SalaryChangeRequested,
                'Pengajuan perubahan gaji',
                "SDM mengajukan perubahan gaji pokok {$locked->name}: ".self::rupiah($oldCents).' → '.self::rupiah($newCents).'.',
                ['employee_id' => $locked->id, 'salary_change_id' => $change->id],
            );

            return $change;
        });
    }

    public function approve(SalaryChange $change, User $actor): SalaryChange
    {
        return DB::transaction(function () use ($change, $actor) {
            $locked = SalaryChange::query()->lockForUpdate()->findOrFail($change->id);
            $this->assertPending($locked);

            $employee = Employee::query()->lockForUpdate()->findOrFail($locked->employee_id);
            $oldBase = $employee->base_salary;
            $applyNow = $locked->effective_date->lte(today());

            $locked->update([
                'status' => SalaryChangeStatus::Approved,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'applied_at' => $applyNow ? now() : null,
            ]);

            if ($applyNow) {
                $employee->update(['base_salary' => $locked->new_salary]);
            }

            $this->auditLogService->record('hr.salary_change_approved', $locked, [
                'status' => SalaryChangeStatus::Pending->value,
                'base_salary' => $oldBase,
            ], [
                'status' => SalaryChangeStatus::Approved->value,
                'employee_id' => $employee->id,
                'employee_name' => $employee->name,
                'new_salary' => $locked->new_salary,
                'effective_date' => $locked->effective_date->toDateString(),
                'base_salary' => $applyNow ? $locked->new_salary : $oldBase,
                'applied' => $applyNow,
            ], $actor);

            $this->notifyRequester($locked, $employee, $applyNow
                ? "CEO menyetujui perubahan gaji pokok {$employee->name} menjadi ".self::rupiah(self::cents($locked->new_salary)).'.'
                : "CEO menyetujui perubahan gaji pokok {$employee->name} menjadi ".self::rupiah(self::cents($locked->new_salary)).', berlaku '.$locked->effective_date->translatedFormat('d F Y').'.');

            return $locked;
        });
    }

    public function reject(SalaryChange $change, string $note, User $actor): SalaryChange
    {
        if (trim($note) === '') {
            throw ValidationException::withMessages(['reject_note' => 'Alasan penolakan wajib diisi.']);
        }

        return DB::transaction(function () use ($change, $note, $actor) {
            $locked = SalaryChange::query()->lockForUpdate()->findOrFail($change->id);
            $this->assertPending($locked);

            $locked->update([
                'status' => SalaryChangeStatus::Rejected,
                'reject_note' => $note,
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ]);

            $employee = $locked->employee;

            $this->auditLogService->record('hr.salary_change_rejected', $locked, [
                'status' => SalaryChangeStatus::Pending->value,
            ], [
                'status' => SalaryChangeStatus::Rejected->value,
                'employee_id' => $employee->id,
                'employee_name' => $employee->name,
                'new_salary' => $locked->new_salary,
                'reject_note' => $note,
            ], $actor);

            $this->notifyRequester($locked, $employee, "CEO menolak perubahan gaji pokok {$employee->name}: {$note}");

            return $locked;
        });
    }

    /**
     * Applies every APPROVED change whose effective date has arrived and
     * that hasn't reached `employees.base_salary` yet. Idempotent — an
     * applied row is stamped `applied_at` and skipped by the next run.
     */
    public function applyDue(): int
    {
        $applied = 0;

        foreach ($this->scheduledQuery()->whereDate('effective_date', '<=', today()->toDateString())->orderBy('effective_date')->pluck('id') as $id) {
            $applied += DB::transaction(function () use ($id) {
                $change = SalaryChange::query()->lockForUpdate()->find($id);

                if (! $change || $change->status !== SalaryChangeStatus::Approved || $change->applied_at !== null) {
                    return 0;
                }

                $employee = Employee::query()->lockForUpdate()->findOrFail($change->employee_id);
                $oldBase = $employee->base_salary;

                $change->update(['applied_at' => now()]);
                $employee->update(['base_salary' => $change->new_salary]);

                // Scheduler run: no request user → recorded as "Sistem".
                $this->auditLogService->record('hr.salary_change_applied', $change, [
                    'base_salary' => $oldBase,
                ], [
                    'employee_id' => $employee->id,
                    'employee_name' => $employee->name,
                    'base_salary' => $change->new_salary,
                    'effective_date' => $change->effective_date->toDateString(),
                ]);

                return 1;
            });
        }

        return $applied;
    }

    /**
     * Data of the "Gaji" tab for one employee (profile page and the
     * employee's own "Milik Saya" page). `$selfView` keeps undecided and
     * rejected requests out of the employee's own page — only final,
     * approved changes are theirs to see.
     *
     * @return array<string, mixed>
     */
    public function forEmployee(Employee $employee, bool $selfView = false): array
    {
        $changes = SalaryChange::query()
            ->where('employee_id', $employee->id)
            ->when($selfView, fn (Builder $query) => $query->where('status', SalaryChangeStatus::Approved->value))
            ->with(['requester:id,name', 'decider:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $payments = SalaryPayment::query()
            ->where('employee_id', $employee->id)
            ->orderByDesc('period')
            ->limit(24)
            ->get(['id', 'period', 'base_salary', 'allowance', 'deduction', 'amount', 'paid_at']);

        $year = (string) now()->year;
        $pending = $changes->first(fn (SalaryChange $change) => $change->status === SalaryChangeStatus::Pending);
        $scheduled = $changes->first(fn (SalaryChange $change) => $change->status === SalaryChangeStatus::Approved && $change->applied_at === null);

        return [
            'base_salary' => $employee->base_salary,
            'pending' => $pending ? $this->present($pending) : null,
            'scheduled' => $scheduled ? $this->present($scheduled) : null,
            'changes' => $changes->map(fn (SalaryChange $change) => $this->present($change))->values(),
            'payments' => $payments->map(fn (SalaryPayment $payment) => [
                ...$payment->only(['id', 'period', 'base_salary', 'allowance', 'deduction', 'amount']),
                'paid_at' => $payment->paid_at?->toDateString(),
            ])->values(),
            'paid_this_year' => round((float) SalaryPayment::query()
                ->where('employee_id', $employee->id)
                ->where('period', 'like', "{$year}-%")
                ->sum('amount'), 2),
            'year' => (int) $year,
            'loan_remaining' => $employee->user_id
                ? round((float) StaffLoan::query()->where('staff_id', $employee->user_id)->where('remaining', '>', 0)->sum('remaining'), 2)
                : null,
        ];
    }

    /**
     * Figures for the SDM dashboard.
     *
     * @return array<string, mixed>
     */
    public function dashboardSummary(): array
    {
        $pending = SalaryChange::query()
            ->pending()
            ->whereHas('employee', fn (Builder $query) => $query->hrEligible());

        $lastMonth = now()->subMonthNoOverflow()->format('Y-m');

        return [
            'pending_count' => (clone $pending)->count(),
            'pending' => (clone $pending)
                ->with(['employee:id,name,position_id', 'employee.position:id,name', 'requester:id,name'])
                ->orderBy('created_at')
                ->limit(5)
                ->get()
                ->map(fn (SalaryChange $change) => $this->present($change))
                ->values(),
            'scheduled_count' => $this->scheduledQuery()->whereHas('employee', fn (Builder $query) => $query->hrEligible())->count(),
            'paid_last_month' => round((float) SalaryPayment::query()
                ->forPeriod($lastMonth)
                ->whereIn('employee_id', Employee::query()->hrEligible()->select('id'))
                ->sum('amount'), 2),
            'last_month' => $lastMonth,
            'last_month_label' => PayrollService::periodLabel($lastMonth),
            'base_salary_total' => round((float) Employee::query()->hrEligible()->where('is_active', true)->sum('base_salary'), 2),
        ];
    }

    /**
     * "Rekap beban gaji per bulan" — total salary paid per month for the
     * last `$months` months (oldest first, empty months included).
     *
     * @return array<int, array{period: string, label: string, total: float, count: int}>
     */
    public function monthlyTotals(int $months = 12): array
    {
        $latest = now()->startOfMonth();
        $first = $latest->copy()->subMonthsNoOverflow($months - 1)->format('Y-m');

        $totals = SalaryPayment::query()
            ->where('period', '>=', $first)
            ->where('period', '<=', $latest->format('Y-m'))
            ->whereIn('employee_id', Employee::query()->hrEligible()->select('id'))
            ->groupBy('period')
            ->selectRaw('period, SUM(amount) as total, COUNT(*) as payments')
            ->get()
            ->keyBy('period');

        return collect(range($months - 1, 0))
            ->map(function (int $back) use ($latest, $totals) {
                $period = $latest->copy()->subMonthsNoOverflow($back)->format('Y-m');

                return [
                    'period' => $period,
                    'label' => PayrollService::periodLabel($period),
                    'total' => round((float) ($totals->get($period)?->total ?? 0), 2),
                    'count' => (int) ($totals->get($period)?->payments ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * "Rekap beban gaji per jabatan" — salary paid in `$period` grouped by
     * the employee's current division and position.
     *
     * @return array{period: string, label: string, total: float, by_division: array<int, array<string, mixed>>, by_position: array<int, array<string, mixed>>}
     */
    public function periodBreakdown(string $period): array
    {
        $payments = SalaryPayment::query()
            ->forPeriod($period)
            ->whereIn('employee_id', Employee::query()->hrEligible()->select('id'))
            ->with(['employee:id,name,position_id', 'employee.position:id,name,division_id', 'employee.position.division:id,name'])
            ->get(['id', 'employee_id', 'period', 'base_salary', 'allowance', 'deduction', 'amount']);

        $row = fn ($group, string $name, ?string $division = null) => [
            'name' => $name,
            'division' => $division,
            'employees' => $group->count(),
            'base_salary' => round((float) $group->sum('base_salary'), 2),
            'allowance' => round((float) $group->sum('allowance'), 2),
            'deduction' => round((float) $group->sum('deduction'), 2),
            'total' => round((float) $group->sum('amount'), 2),
        ];

        return [
            'period' => $period,
            'label' => PayrollService::periodLabel($period),
            'total' => round((float) $payments->sum('amount'), 2),
            'by_division' => $payments
                ->groupBy(fn (SalaryPayment $payment) => $payment->employee?->position?->division?->name ?? 'Tanpa divisi')
                ->map(fn ($group, $name) => $row($group, (string) $name))
                ->sortByDesc('total')
                ->values()
                ->all(),
            'by_position' => $payments
                ->groupBy(fn (SalaryPayment $payment) => $payment->employee?->position_id ?? 0)
                ->map(fn ($group) => $row(
                    $group,
                    $group->first()->employee?->position?->name ?? 'Tanpa jabatan',
                    $group->first()->employee?->position?->division?->name,
                ))
                ->sortByDesc('total')
                ->values()
                ->all(),
        ];
    }

    /** One change as the UI reads it. */
    public function present(SalaryChange $change): array
    {
        return [
            ...$change->only(['id', 'employee_id', 'old_salary', 'new_salary', 'reason', 'reject_note', 'performance_review_id', 'requested_by', 'decided_by']),
            'status' => $change->status->value,
            'effective_date' => $change->effective_date->toDateString(),
            'decided_at' => $change->decided_at?->toISOString(),
            'applied_at' => $change->applied_at?->toISOString(),
            'created_at' => $change->created_at?->toISOString(),
            'requester' => $change->relationLoaded('requester') && $change->requester ? $change->requester->only(['id', 'name']) : null,
            'decider' => $change->relationLoaded('decider') && $change->decider ? $change->decider->only(['id', 'name']) : null,
            'employee' => $change->relationLoaded('employee') && $change->employee ? [
                'id' => $change->employee->id,
                'name' => $change->employee->name,
                'position' => $change->employee->relationLoaded('position') && $change->employee->position
                    ? $change->employee->position->only(['id', 'name', 'division_id']) + [
                        'division' => $change->employee->position->relationLoaded('division') ? $change->employee->position->division?->only(['id', 'name']) : null,
                    ]
                    : null,
            ] : null,
        ];
    }

    /** APPROVED changes still waiting for their effective date. */
    private function scheduledQuery(): Builder
    {
        return SalaryChange::query()
            ->where('status', SalaryChangeStatus::Approved->value)
            ->whereNull('applied_at');
    }

    private function assertPending(SalaryChange $change): void
    {
        if ($change->status !== SalaryChangeStatus::Pending) {
            throw ValidationException::withMessages(['status' => 'Pengajuan ini sudah diputuskan sebelumnya.']);
        }
    }

    private function notifyRequester(SalaryChange $change, Employee $employee, string $message): void
    {
        $requester = $change->requester;

        if ($requester && $requester->is_active) {
            $this->notificationService->notify(
                $requester,
                NotificationType::SalaryChangeDecided,
                $change->status === SalaryChangeStatus::Approved ? 'Perubahan gaji disetujui' : 'Perubahan gaji ditolak',
                $message,
                ['employee_id' => $employee->id, 'salary_change_id' => $change->id],
            );
        }
    }

    private static function cents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }

    private static function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private static function rupiah(int $cents): string
    {
        return 'Rp '.number_format($cents / 100, 0, ',', '.');
    }
}
