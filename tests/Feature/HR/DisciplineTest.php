<?php

use App\Enums\DisciplinaryType;
use App\Exports\DisciplinaryRecordsExport;
use App\Models\AuditLog;
use App\Models\DisciplinaryRecord;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\User;
use App\Services\DisciplineService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function disciplineActor(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function disciplinePayload(Employee $employee, array $overrides = []): array
{
    return [
        'employee_id' => $employee->id,
        'type' => 'SP1',
        'issued_on' => now()->toDateString(),
        'description' => 'Terlambat masuk kerja berulang kali.',
        ...$overrides,
    ];
}

/** Issues straight through the service, bypassing HTTP. */
function issueDiscipline(Employee $employee, string $type, ?string $issuedOn = null): DisciplinaryRecord
{
    return app(DisciplineService::class)->issue($employee, [
        'type' => $type,
        'issued_on' => $issuedOn ?? now()->toDateString(),
        'description' => "Catatan {$type}",
    ], disciplineActor('HR'));
}

// ── RBAC ────────────────────────────────────────────────────────────────

test('CEO, HR and SUPERADMIN can open the Kedisiplinan recap', function (string $role) {
    DisciplinaryRecord::factory()->sp(1)->create();

    $this->actingAs(disciplineActor($role))->get(route('hr.discipline.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('HR/Discipline/Index')->has('records.data', 1));
})->with(['CEO', 'HR', 'SUPERADMIN']);

test('every other role is refused the Kedisiplinan routes', function (string $role) {
    $employee = Employee::factory()->create();
    $record = DisciplinaryRecord::factory()->create(['employee_id' => $employee->id]);
    $user = disciplineActor($role);

    $this->actingAs($user)->get(route('hr.discipline.index'))->assertForbidden();
    $this->actingAs($user)->get(route('hr.discipline.export'))->assertForbidden();
    $this->actingAs($user)->post(route('hr.discipline.store'), disciplinePayload($employee))->assertForbidden();
    $this->actingAs($user)->post(route('hr.discipline.void', $record), ['reason' => 'Salah input data'])->assertForbidden();
})->with(['FINANCE', 'PM', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'QA', 'LOGISTICS', 'FIELD_STAFF']);

test('the CEO reads but cannot issue or cancel', function () {
    $ceo = disciplineActor('CEO');
    $employee = Employee::factory()->create();
    $record = DisciplinaryRecord::factory()->create(['employee_id' => $employee->id]);

    $this->actingAs($ceo)->post(route('hr.discipline.store'), disciplinePayload($employee))->assertForbidden();
    $this->actingAs($ceo)->post(route('hr.discipline.void', $record), ['reason' => 'Salah input data'])->assertForbidden();

    $this->actingAs($ceo)->get(route('hr.discipline.index'))
        ->assertInertia(fn (Assert $page) => $page->where('canManage', false)->has('employees', 0));
    $this->actingAs(disciplineActor('HR'))->get(route('hr.discipline.index'))
        ->assertInertia(fn (Assert $page) => $page->where('canManage', true)->has('employees', 1)->where('employees.0.next_sp', 'SP1'));
});

// ── Issuing ─────────────────────────────────────────────────────────────

test('HR issues an SP1 valid six months, audited, and the employee is notified', function () {
    $account = disciplineActor('DESIGNER');
    $employee = Employee::factory()->create(['user_id' => $account->id]);
    $hr = disciplineActor('HR');

    $this->actingAs($hr)->post(route('hr.discipline.store'), disciplinePayload($employee))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $record = DisciplinaryRecord::sole();
    expect($record->type)->toBe(DisciplinaryType::Sp1)
        ->and($record->valid_until->toDateString())->toBe(now()->addMonthsNoOverflow(6)->toDateString())
        ->and($record->recorded_by)->toBe($hr->id);

    $audit = AuditLog::where('action', 'hr.discipline_recorded')->sole();
    expect($audit->user_id)->toBe($hr->id)
        ->and($audit->new_values['type'])->toBe('SP1')
        ->and($audit->new_values['employee_id'])->toBe($employee->id);

    $notification = Notification::where('user_id', $account->id)->sole();
    expect($notification->type)->toBe('disciplinary_issued')
        ->and($notification->metadata)->toBe(['employee_id' => $employee->id]);
});

test('an SP validity override must be after the issue date; non-SP rows have none', function () {
    $employee = Employee::factory()->create();
    $hr = disciplineActor('HR');

    $this->actingAs($hr)->post(route('hr.discipline.store'), disciplinePayload($employee, ['valid_until' => now()->subDay()->toDateString()]))
        ->assertSessionHasErrors(['valid_until' => 'Masa berlaku harus setelah tanggal terbit.']);

    $this->actingAs($hr)->post(route('hr.discipline.store'), disciplinePayload($employee, ['valid_until' => now()->addMonths(3)->toDateString()]))
        ->assertSessionHasNoErrors();
    expect(DisciplinaryRecord::sole()->valid_until->toDateString())->toBe(now()->addMonths(3)->toDateString());

    $this->actingAs($hr)->post(route('hr.discipline.store'), disciplinePayload($employee, ['type' => 'TEGURAN_LISAN', 'valid_until' => now()->addYear()->toDateString()]))
        ->assertSessionHasNoErrors();
    expect(DisciplinaryRecord::where('type', 'TEGURAN_LISAN')->sole()->valid_until)->toBeNull();
});

test('validation messages are in Indonesian and the issue date cannot be in the future', function () {
    $employee = Employee::factory()->create();

    $this->actingAs(disciplineActor('HR'))->post(route('hr.discipline.store'), [
        'employee_id' => $employee->id,
        'type' => 'PEMBATALAN',
        'issued_on' => now()->addDay()->toDateString(),
        'description' => '',
        'link' => 'javascript:alert(1)',
    ])->assertSessionHasErrors([
        'type' => 'Jenis catatan tidak valid.',
        'issued_on' => 'Tanggal terbit tidak boleh di masa depan.',
        'description' => 'Uraian wajib diisi.',
        'link' => 'Link dokumen harus berupa URL http/https yang valid.',
    ]);

    expect(DisciplinaryRecord::count())->toBe(0);
});

test('inactive employees and field-staff-linked employees are refused', function () {
    $inactive = Employee::factory()->inactive()->create();
    $tukang = disciplineActor('FIELD_STAFF');
    // Linked before the role existed on the account — the scope is the second layer.
    $linked = Employee::factory()->create(['user_id' => $tukang->id]);
    $hr = disciplineActor('HR');

    $this->actingAs($hr)->post(route('hr.discipline.store'), disciplinePayload($inactive))
        ->assertSessionHasErrors('employee_id');
    $this->actingAs($hr)->post(route('hr.discipline.store'), disciplinePayload($linked))
        ->assertSessionHasErrors(['employee_id' => 'Karyawan tidak ditemukan di modul SDM.']);

    DisciplinaryRecord::factory()->create(['employee_id' => $linked->id]);

    $this->actingAs($hr)->get(route('hr.discipline.index'))
        ->assertInertia(fn (Assert $page) => $page->has('records.data', 0)
            ->where('employees', fn ($employees) => collect($employees)->pluck('id')->doesntContain($linked->id)));
});

// ── SP escalation (decision #12) ────────────────────────────────────────

test('skipping a level is refused with a message naming the SP to issue', function () {
    $employee = Employee::factory()->create(['name' => 'Ibnu']);

    $this->actingAs(disciplineActor('HR'))->post(route('hr.discipline.store'), disciplinePayload($employee, ['type' => 'SP2']))
        ->assertSessionHasErrors(['type' => 'Ibnu tidak punya SP yang masih berlaku pada '.now()->translatedFormat('d F Y').' — SP harus dimulai dari SP1, bukan SP2.']);

    issueDiscipline($employee, 'SP1');

    $this->actingAs(disciplineActor('HR'))->post(route('hr.discipline.store'), disciplinePayload($employee, ['type' => 'SP3']))
        ->assertSessionHasErrors('type');
    expect(session('errors')->first('type'))->toContain('SP1 Ibnu masih berlaku')->toContain('adalah SP2');
});

test('repeating the level in force is refused', function () {
    $employee = Employee::factory()->create();
    issueDiscipline($employee, 'SP1');

    $this->actingAs(disciplineActor('HR'))->post(route('hr.discipline.store'), disciplinePayload($employee, ['type' => 'SP1']))
        ->assertSessionHasErrors('type');
    expect(session('errors')->first('type'))->toContain('adalah SP2, bukan SP1');
    expect(DisciplinaryRecord::count())->toBe(1);
});

test('SP1 → SP2 → SP3 escalate, and SP3 is the last level', function () {
    $employee = Employee::factory()->create();
    $service = app(DisciplineService::class);

    expect($service->nextSpLevel($employee))->toBe(DisciplinaryType::Sp1);
    issueDiscipline($employee, 'SP1');
    expect($service->nextSpLevel($employee))->toBe(DisciplinaryType::Sp2);
    issueDiscipline($employee, 'SP2');
    expect($service->nextSpLevel($employee))->toBe(DisciplinaryType::Sp3);
    issueDiscipline($employee, 'SP3');
    expect($service->nextSpLevel($employee))->toBeNull();

    foreach (['SP1', 'SP2', 'SP3'] as $type) {
        $this->actingAs(disciplineActor('HR'))->post(route('hr.discipline.store'), disciplinePayload($employee, ['type' => $type]))
            ->assertSessionHasErrors('type');
        expect(session('errors')->first('type'))->toContain('SP3 adalah tingkat terakhir');
    }

    // Reprimands and notes are never bound to the level.
    issueDiscipline($employee, 'TEGURAN_LISAN');
    issueDiscipline($employee, 'CATATAN');
    expect(DisciplinaryRecord::count())->toBe(5);
});

test('an expired SP resets the level to SP1', function () {
    $employee = Employee::factory()->create();
    DisciplinaryRecord::factory()->sp(1, 200)->create(['employee_id' => $employee->id]);
    DisciplinaryRecord::factory()->sp(2, 190)->create(['employee_id' => $employee->id]);

    expect(app(DisciplineService::class)->nextSpLevel($employee))->toBe(DisciplinaryType::Sp1);

    $this->actingAs(disciplineActor('HR'))->post(route('hr.discipline.store'), disciplinePayload($employee, ['type' => 'SP3']))
        ->assertSessionHasErrors('type');
    $this->actingAs(disciplineActor('HR'))->post(route('hr.discipline.store'), disciplinePayload($employee, ['type' => 'SP1']))
        ->assertSessionHasNoErrors();
});

test('the level is computed on the issue date, so a backdated SP2 needs SP1 in force then', function () {
    $employee = Employee::factory()->create();
    DisciplinaryRecord::factory()->sp(1, 10)->create(['employee_id' => $employee->id]);

    // 20 days ago SP1 didn't exist yet.
    $this->actingAs(disciplineActor('HR'))->post(route('hr.discipline.store'), disciplinePayload($employee, [
        'type' => 'SP2',
        'issued_on' => now()->subDays(20)->toDateString(),
    ]))->assertSessionHasErrors('type');
});

test('a voided SP does not count towards the level', function () {
    $employee = Employee::factory()->create();
    $sp1 = issueDiscipline($employee, 'SP1');

    app(DisciplineService::class)->void($sp1, 'Salah karyawan', disciplineActor('HR'));

    expect(app(DisciplineService::class)->nextSpLevel($employee))->toBe(DisciplinaryType::Sp1);

    $this->actingAs(disciplineActor('HR'))->post(route('hr.discipline.store'), disciplinePayload($employee, ['type' => 'SP2']))
        ->assertSessionHasErrors('type');
});

// ── Cancelling (append-only) ────────────────────────────────────────────

test('HR cancels a record with a PEMBATALAN entry, audited, and never twice', function () {
    $employee = Employee::factory()->create();
    $record = issueDiscipline($employee, 'SP1');
    $hr = disciplineActor('HR');

    $this->actingAs($hr)->post(route('hr.discipline.void', $record), ['reason' => ''])
        ->assertSessionHasErrors(['reason' => 'Alasan pembatalan wajib diisi.']);

    $this->actingAs($hr)->post(route('hr.discipline.void', $record), ['reason' => 'Salah input karyawan'])
        ->assertSessionHasNoErrors();

    $void = DisciplinaryRecord::where('type', 'PEMBATALAN')->sole();
    expect($void->voids_id)->toBe($record->id)
        ->and($void->description)->toBe('Salah input karyawan')
        ->and($void->issued_on->toDateString())->toBe(today()->toDateString())
        ->and($record->fresh()->type)->toBe(DisciplinaryType::Sp1);

    $audit = AuditLog::where('action', 'hr.discipline_voided')->sole();
    expect($audit->old_values['record_id'])->toBe($record->id)
        ->and($audit->new_values['reason'])->toBe('Salah input karyawan');

    $this->actingAs($hr)->post(route('hr.discipline.void', $record), ['reason' => 'Lagi dibatalkan'])
        ->assertSessionHasErrors(['reason' => 'Catatan ini sudah dibatalkan sebelumnya.']);
    $this->actingAs($hr)->post(route('hr.discipline.void', $void), ['reason' => 'Batalkan pembatalan'])
        ->assertSessionHasErrors(['reason' => 'Entri pembatalan tidak bisa dibatalkan lagi.']);

    expect(DisciplinaryRecord::count())->toBe(2);
});

test('records are append-only — no DELETE route and the model refuses update/delete', function () {
    $methods = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'hr/discipline'))
        ->flatMap(fn ($route) => $route->methods());

    expect($methods->contains('DELETE'))->toBeFalse()
        ->and($methods->contains('PUT'))->toBeFalse()
        ->and($methods->contains('PATCH'))->toBeFalse();

    $record = DisciplinaryRecord::factory()->create();

    expect(fn () => $record->update(['description' => 'Diubah']))->toThrow(LogicException::class)
        ->and(fn () => $record->delete())->toThrow(LogicException::class);
});

// ── Recap, export, profile tab ──────────────────────────────────────────

test('the recap filters by type, active SP and date range', function () {
    $employee = Employee::factory()->create();
    DisciplinaryRecord::factory()->sp(1, 200)->create(['employee_id' => $employee->id]);
    DisciplinaryRecord::factory()->sp(1, 5)->create(['employee_id' => $employee->id]);
    DisciplinaryRecord::factory()->create(['employee_id' => $employee->id, 'issued_on' => now()->subDays(3)->toDateString()]);
    $ceo = disciplineActor('CEO');

    $this->actingAs($ceo)->get(route('hr.discipline.index', ['type' => 'SP1']))
        ->assertInertia(fn (Assert $page) => $page->has('records.data', 2));
    $this->actingAs($ceo)->get(route('hr.discipline.index', ['active_only' => 1]))
        ->assertInertia(fn (Assert $page) => $page->has('records.data', 1)->where('records.data.0.is_active_sp', true)
            ->where('stats.active_sp_count', 1));
    $this->actingAs($ceo)->get(route('hr.discipline.index', ['from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString()]))
        ->assertInertia(fn (Assert $page) => $page->has('records.data', 2));
});

test('the Excel export uses the page filters', function () {
    $employee = Employee::factory()->create();
    DisciplinaryRecord::factory()->sp(1)->create(['employee_id' => $employee->id]);
    DisciplinaryRecord::factory()->create(['employee_id' => $employee->id]);

    Excel::fake();
    $this->actingAs(disciplineActor('CEO'))->get(route('hr.discipline.export', ['type' => 'SP1']))->assertOk();

    Excel::assertDownloaded('kedisiplinan-'.now()->format('Y-m-d').'.xlsx', function (DisciplinaryRecordsExport $export) {
        $rows = $export->collection();

        return $export->filters()['type'] === 'SP1'
            && $rows->count() === 1
            && $export->map($rows->first())[8] === 'Berlaku';
    });
});

test('the employee profile tab shows the record history and the next SP level', function () {
    $employee = Employee::factory()->create();
    $sp1 = issueDiscipline($employee, 'SP1');
    issueDiscipline($employee, 'TEGURAN_LISAN');

    $this->actingAs(disciplineActor('CEO'))->get(route('hr.employees.show', $employee))
        ->assertInertia(fn (Assert $page) => $page
            ->where('discipline.next_sp', 'SP2')
            ->where('discipline.active_sp.id', $sp1->id)
            ->has('discipline.records', 2)
            ->where('discipline.counts.sp', 1)
            ->where('discipline.counts.teguran', 1));
});

test('the dashboard summary counts active SPs of HR-eligible employees', function () {
    $employee = Employee::factory()->create(['name' => 'Ami']);
    issueDiscipline($employee, 'SP1');
    DisciplinaryRecord::factory()->sp(1, 200)->create();

    $summary = app(DisciplineService::class)->dashboardSummary();

    expect($summary['active_sp_count'])->toBe(1)
        ->and($summary['employees_with_active_sp'])->toBe(1)
        ->and($summary['issued_this_month'])->toBeGreaterThanOrEqual(1)
        ->and($summary['latest_active_sp'][0]['employee']['name'])->toBe('Ami');
});
