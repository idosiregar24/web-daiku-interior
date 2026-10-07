<?php

namespace App\Services;

use App\Enums\DisciplinaryType;
use App\Enums\NotificationType;
use App\Models\DisciplinaryRecord;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SDM (Sprint 10, §3.1) — reprimands and warning letters, recorded by HR.
 *
 * Append-only: a record is never edited or deleted (the model refuses);
 * a mistake is cancelled by a PEMBATALAN row pointing at it (`voids_id`).
 *
 * SP escalation is blocking (decision #12): SP1 → SP2 → SP3, each level
 * only while the previous one is still in force on the issue date. Voided
 * SPs never count; once every SP has expired the level resets to SP1.
 * Enforced here inside the transaction with the employee row locked, so
 * two HR clicks can't both issue "the next" level.
 */
class DisciplineService
{
    /** Default validity of a warning letter (§3.1). */
    public const SP_VALID_MONTHS = 6;

    /** Types HR can record directly (PEMBATALAN only comes from void()). */
    public const ISSUABLE_TYPES = [
        DisciplinaryType::TeguranLisan,
        DisciplinaryType::Sp1,
        DisciplinaryType::Sp2,
        DisciplinaryType::Sp3,
        DisciplinaryType::Catatan,
    ];

    public function __construct(
        private AuditLogService $auditLogService,
        private NotificationService $notificationService,
    ) {}

    /**
     * @param  array{type: string, issued_on: string, valid_until?: string|null, description: string, link?: string|null}  $data
     */
    public function issue(Employee $employee, array $data, User $actor): DisciplinaryRecord
    {
        $type = DisciplinaryType::tryFrom((string) ($data['type'] ?? ''));

        if (! in_array($type, self::ISSUABLE_TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'Jenis catatan tidak valid.']);
        }

        $issuedOn = Carbon::parse($data['issued_on'])->startOfDay();

        if ($issuedOn->isAfter(today())) {
            throw ValidationException::withMessages(['issued_on' => 'Tanggal terbit tidak boleh di masa depan.']);
        }

        $record = DB::transaction(function () use ($employee, $data, $actor, $type, $issuedOn) {
            // Serializes concurrent issues for the same employee so the
            // escalation check below sees an SP the other request just wrote.
            $locked = Employee::query()->hrEligible()->lockForUpdate()->find($employee->id);

            if (! $locked) {
                throw ValidationException::withMessages(['employee_id' => 'Karyawan tidak ditemukan di modul SDM.']);
            }

            if (! $locked->is_active) {
                throw ValidationException::withMessages([
                    'employee_id' => "{$locked->name} sudah nonaktif — catatan kedisiplinan tidak bisa ditambahkan.",
                ]);
            }

            $validUntil = null;

            if ($type->spLevel() !== null) {
                $this->assertEscalation($locked, $type, $issuedOn);

                $validUntil = filled($data['valid_until'] ?? null)
                    ? Carbon::parse($data['valid_until'])->startOfDay()
                    : $issuedOn->copy()->addMonthsNoOverflow(self::SP_VALID_MONTHS);

                if (! $validUntil->isAfter($issuedOn)) {
                    throw ValidationException::withMessages(['valid_until' => 'Masa berlaku harus setelah tanggal terbit.']);
                }
            }

            $record = DisciplinaryRecord::create([
                'employee_id' => $locked->id,
                'type' => $type,
                'issued_on' => $issuedOn->toDateString(),
                'valid_until' => $validUntil?->toDateString(),
                'description' => $data['description'],
                'link' => $data['link'] ?? null,
                'recorded_by' => $actor->id,
            ]);

            $this->auditLogService->record('hr.discipline_recorded', $record, null, [
                'employee_id' => $locked->id,
                'employee_name' => $locked->name,
                'type' => $type->value,
                'issued_on' => $record->issued_on->toDateString(),
                'valid_until' => $record->valid_until?->toDateString(),
                'description' => $record->description,
                'link' => $record->link,
            ], $actor);

            $user = $locked->user;

            if ($user && $user->is_active) {
                $this->notificationService->notify(
                    $user,
                    NotificationType::DisciplinaryIssued,
                    $type->spLevel() !== null ? "{$type->label()} diterbitkan" : "{$type->label()} dicatat",
                    $type->spLevel() !== null
                        ? "SDM menerbitkan {$type->label()} untuk Anda, berlaku sampai {$record->valid_until->translatedFormat('d F Y')}."
                        : "SDM mencatat {$type->label()} untuk Anda pada {$record->issued_on->translatedFormat('d F Y')}.",
                    ['employee_id' => $locked->id],
                );
            }

            return $record;
        });

        return $record->load('recorder:id,name');
    }

    /** Cancels `$record` with a PEMBATALAN row — the original stays, it just stops counting. */
    public function void(DisciplinaryRecord $record, string $reason, User $actor): DisciplinaryRecord
    {
        return DB::transaction(function () use ($record, $reason, $actor) {
            $employee = Employee::query()->hrEligible()->lockForUpdate()->find($record->employee_id);

            if (! $employee) {
                throw ValidationException::withMessages(['reason' => 'Karyawan tidak ditemukan di modul SDM.']);
            }

            $record->refresh();

            if ($record->type === DisciplinaryType::Pembatalan) {
                throw ValidationException::withMessages(['reason' => 'Entri pembatalan tidak bisa dibatalkan lagi.']);
            }

            if ($record->voidedBy()->exists()) {
                throw ValidationException::withMessages(['reason' => 'Catatan ini sudah dibatalkan sebelumnya.']);
            }

            try {
                $void = DisciplinaryRecord::create([
                    'employee_id' => $employee->id,
                    'type' => DisciplinaryType::Pembatalan,
                    'issued_on' => today()->toDateString(),
                    'valid_until' => null,
                    'description' => $reason,
                    'voids_id' => $record->id,
                    'recorded_by' => $actor->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                // UNIQUE(voids_id) backstop.
                throw ValidationException::withMessages(['reason' => 'Catatan ini sudah dibatalkan sebelumnya.']);
            }

            $this->auditLogService->record('hr.discipline_voided', $void, [
                'record_id' => $record->id,
                'type' => $record->type->value,
                'issued_on' => $record->issued_on->toDateString(),
                'valid_until' => $record->valid_until?->toDateString(),
            ], [
                'employee_id' => $employee->id,
                'employee_name' => $employee->name,
                'voids_id' => $record->id,
                'reason' => $reason,
            ], $actor);

            return $void;
        });
    }

    /**
     * The only SP level that may be issued to `$employee` on `$date`
     * (default today): SP1 when no SP is in force, otherwise one above the
     * highest SP in force — null when SP3 is in force (last level).
     */
    public function nextSpLevel(Employee $employee, ?Carbon $date = null): ?DisciplinaryType
    {
        return self::levelAfter($this->activeSpRecord($employee, $date)?->type);
    }

    /** The highest effective SP in force on `$date` (default today), if any. */
    public function activeSpRecord(Employee $employee, ?Carbon $date = null): ?DisciplinaryRecord
    {
        return DisciplinaryRecord::query()
            ->where('employee_id', $employee->id)
            ->activeSp($date)
            ->get()
            ->sortByDesc(fn (DisciplinaryRecord $record) => [$record->type->spLevel(), $record->valid_until->toDateString()])
            ->first();
    }

    /**
     * Data of the "Kedisiplinan" tab for one employee (profile page and the
     * employee's own "Milik Saya" page).
     *
     * @return array<string, mixed>
     */
    public function forEmployee(Employee $employee): array
    {
        $records = DisciplinaryRecord::query()
            ->where('employee_id', $employee->id)
            ->with(['recorder:id,name', 'voidedBy:id,voids_id,description,issued_on,recorded_by', 'voidedBy.recorder:id,name', 'voids:id,type,issued_on'])
            ->orderByDesc('issued_on')
            ->orderByDesc('id')
            ->get();

        $today = today();
        $active = $this->activeSpRecord($employee);
        $next = $this->nextSpLevel($employee);
        $effective = $records->filter(fn (DisciplinaryRecord $record) => $record->type !== DisciplinaryType::Pembatalan && ! $record->voidedBy);

        return [
            'records' => $records->map(fn (DisciplinaryRecord $record) => $this->present($record, $today))->values(),
            'active_sp' => $active ? [
                'id' => $active->id,
                'type' => $active->type->value,
                'issued_on' => $active->issued_on->toDateString(),
                'valid_until' => $active->valid_until->toDateString(),
            ] : null,
            'next_sp' => $next?->value,
            'counts' => [
                'teguran' => $effective->where('type', DisciplinaryType::TeguranLisan)->count(),
                'sp' => $effective->filter(fn (DisciplinaryRecord $record) => $record->type->spLevel() !== null)->count(),
                'catatan' => $effective->where('type', DisciplinaryType::Catatan)->count(),
                'voided' => $records->where('type', DisciplinaryType::Pembatalan)->count(),
            ],
        ];
    }

    /**
     * Figures for the SDM dashboard.
     *
     * @return array<string, mixed>
     */
    public function dashboardSummary(): array
    {
        $activeSp = DisciplinaryRecord::query()
            ->activeSp()
            ->whereHas('employee', fn (Builder $query) => $query->hrEligible()->where('is_active', true));

        $latest = (clone $activeSp)
            ->with(['employee:id,name,position_id', 'employee.position:id,name'])
            ->orderByDesc('issued_on')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (DisciplinaryRecord $record) => [
                'id' => $record->id,
                'employee' => ['id' => $record->employee->id, 'name' => $record->employee->name, 'position' => $record->employee->position?->name],
                'type' => $record->type->value,
                'issued_on' => $record->issued_on->toDateString(),
                'valid_until' => $record->valid_until->toDateString(),
            ]);

        return [
            'active_sp_count' => (clone $activeSp)->count(),
            'employees_with_active_sp' => (clone $activeSp)->distinct()->count('employee_id'),
            'issued_this_month' => DisciplinaryRecord::query()
                ->effective()
                ->whereBetween('issued_on', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                ->whereHas('employee', fn (Builder $query) => $query->hrEligible())
                ->count(),
            'latest_active_sp' => $latest,
        ];
    }

    /**
     * Records of HR-eligible employees for the recap page and its Excel
     * export (same filters, so the file matches the screen).
     *
     * @param  array{from?: string|null, to?: string|null, division?: int|null, position?: int|null, type?: string|null, active_only?: bool}  $filters
     */
    public function recapQuery(array $filters): Builder
    {
        $type = DisciplinaryType::tryFrom((string) ($filters['type'] ?? ''));

        return DisciplinaryRecord::query()
            ->whereHas('employee', fn (Builder $query) => $query
                ->hrEligible()
                ->inStructure($filters['division'] ?? null, $filters['position'] ?? null))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->whereDate('issued_on', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->whereDate('issued_on', '<=', $to))
            ->when($type, fn (Builder $query) => $query->where('type', $type->value))
            ->when($filters['active_only'] ?? false, fn (Builder $query) => $query->activeSp())
            ->with([
                'employee:id,name,position_id,is_active',
                'employee.position:id,name,division_id',
                'employee.position.division:id,name',
                'recorder:id,name',
                'voidedBy:id,voids_id,description,issued_on,recorded_by',
                'voidedBy.recorder:id,name',
                'voids:id,type,issued_on',
            ])
            ->orderByDesc('issued_on')
            ->orderByDesc('id');
    }

    /**
     * The highest SP in force today per employee id — one query for a
     * whole list (the "Catat" dialog's next-level hints).
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, DisciplinaryRecord>
     */
    public function activeSpByEmployee(array $employeeIds): array
    {
        return DisciplinaryRecord::query()
            ->whereIn('employee_id', $employeeIds)
            ->activeSp()
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($records) => $records->sortByDesc(fn (DisciplinaryRecord $record) => [$record->type->spLevel(), $record->valid_until->toDateString()])->first())
            ->all();
    }

    /** SP level that follows the active one (null active → SP1, SP3 → null). */
    public static function levelAfter(?DisciplinaryType $active): ?DisciplinaryType
    {
        return match ($active) {
            null => DisciplinaryType::Sp1,
            DisciplinaryType::Sp1 => DisciplinaryType::Sp2,
            DisciplinaryType::Sp2 => DisciplinaryType::Sp3,
            default => null,
        };
    }

    /** One record as the UI reads it — derived `is_voided` / `is_active_sp` flags. */
    public function present(DisciplinaryRecord $record, ?Carbon $today = null): array
    {
        $today ??= today();
        $isVoided = $record->relationLoaded('voidedBy') ? $record->voidedBy !== null : $record->voidedBy()->exists();

        return [
            ...$record->only(['id', 'employee_id', 'description', 'link', 'voids_id', 'recorded_by']),
            'type' => $record->type->value,
            'issued_on' => $record->issued_on->toDateString(),
            'valid_until' => $record->valid_until?->toDateString(),
            'created_at' => $record->created_at?->toISOString(),
            'recorder' => $record->recorder ? ['id' => $record->recorder->id, 'name' => $record->recorder->name] : null,
            'is_voided' => $isVoided,
            'is_active_sp' => ! $isVoided
                && $record->type->spLevel() !== null
                && $record->issued_on->lte($today)
                && $record->valid_until?->gte($today),
            'employee' => $record->relationLoaded('employee') && $record->employee ? [
                'id' => $record->employee->id,
                'name' => $record->employee->name,
                'is_active' => (bool) $record->employee->is_active,
                'position' => $record->employee->position ? [
                    'id' => $record->employee->position->id,
                    'name' => $record->employee->position->name,
                    'division_id' => $record->employee->position->division_id,
                    'division' => $record->employee->position->division?->only(['id', 'name']),
                ] : null,
            ] : null,
            'voids' => $record->relationLoaded('voids') && $record->voids ? [
                'id' => $record->voids->id,
                'type' => $record->voids->type->value,
                'issued_on' => $record->voids->issued_on->toDateString(),
            ] : null,
            'void' => $isVoided && $record->relationLoaded('voidedBy') ? [
                'id' => $record->voidedBy->id,
                'reason' => $record->voidedBy->description,
                'issued_on' => $record->voidedBy->issued_on->toDateString(),
                'recorder' => $record->voidedBy->recorder?->name,
            ] : null,
        ];
    }

    /** Decision #12 — refuses skipping or repeating an SP level. */
    private function assertEscalation(Employee $employee, DisciplinaryType $type, Carbon $issuedOn): void
    {
        $active = $this->activeSpRecord($employee, $issuedOn);
        $next = $this->nextSpLevel($employee, $issuedOn);

        if ($next === $type) {
            return;
        }

        $date = $issuedOn->translatedFormat('d F Y');

        if ($next === null) {
            throw ValidationException::withMessages([
                'type' => "SP3 {$employee->name} masih berlaku sampai {$active->valid_until->translatedFormat('d F Y')}. SP3 adalah tingkat terakhir — langkah selanjutnya dilakukan di luar sistem.",
            ]);
        }

        $message = $active
            ? "{$active->type->label()} {$employee->name} masih berlaku sampai {$active->valid_until->translatedFormat('d F Y')} — yang bisa diterbitkan sekarang adalah {$next->label()}, bukan {$type->label()}."
            : "{$employee->name} tidak punya SP yang masih berlaku pada {$date} — SP harus dimulai dari SP1, bukan {$type->label()}.";

        throw ValidationException::withMessages(['type' => $message]);
    }
}
