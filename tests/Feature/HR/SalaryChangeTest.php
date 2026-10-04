<?php

use App\Enums\SalaryChangeStatus;
use App\Jobs\ApplyDueSalaryChangesJob;
use App\Models\AuditLog;
use App\Models\DisciplinaryRecord;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\PerformanceReview;
use App\Models\SalaryChange;
use App\Models\SalaryPayment;
use App\Models\User;
use App\Services\SalaryChangeService;
use Database\Seeders\Demo\DisciplineSalaryDemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function salaryActor(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function salaryEmployee(array $attributes = []): Employee
{
    return Employee::factory()->create(['name' => 'Icha', 'base_salary' => 5_000_000, ...$attributes]);
}

function salaryPayload(Employee $employee, array $overrides = []): array
{
    return [
        'employee_id' => $employee->id,
        'new_salary' => 5_500_000,
        'effective_date' => now()->toDateString(),
        'reason' => 'Kinerja semester 1 sangat baik.',
        ...$overrides,
    ];
}

function requestSalaryChange(Employee $employee, array $overrides = [], ?User $hr = null): SalaryChange
{
    return app(SalaryChangeService::class)->request($employee, salaryPayload($employee, $overrides), $hr ?? salaryActor('HR'));
}

function salaryReview(Employee $employee): PerformanceReview
{
    return PerformanceReview::create([
        'employee_id' => $employee->id,
        'year' => 2026,
        'semester' => 1,
        'status' => 'APPROVED',
        'reviewer_id' => salaryActor('HR')->id,
    ]);
}

// ── RBAC ────────────────────────────────────────────────────────────────

test('CEO, HR and SUPERADMIN can open the Gaji page', function (string $role) {
    requestSalaryChange(salaryEmployee());

    $this->actingAs(salaryActor($role))->get(route('hr.salary.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('HR/Salary/Index')->has('changes.data', 1)->has('recap.months', 12));
})->with(['CEO', 'HR', 'SUPERADMIN']);

test('every other role is refused the Gaji routes', function (string $role) {
    $employee = salaryEmployee();
    $change = SalaryChange::factory()->create(['employee_id' => $employee->id]);
    $user = salaryActor($role);

    $this->actingAs($user)->get(route('hr.salary.index'))->assertForbidden();
    $this->actingAs($user)->post(route('hr.salary-changes.store'), salaryPayload($employee))->assertForbidden();
    $this->actingAs($user)->post(route('hr.salary-changes.approve', $change))->assertForbidden();
    $this->actingAs($user)->post(route('hr.salary-changes.reject', $change), ['reject_note' => 'Tidak'])->assertForbidden();
})->with(['FINANCE', 'PM', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'QA', 'LOGISTICS', 'FIELD_STAFF']);

test('HR requests but cannot decide; the CEO decides but cannot request', function () {
    $employee = salaryEmployee();
    $change = SalaryChange::factory()->create(['employee_id' => $employee->id]);
    $hr = salaryActor('HR');
    $ceo = salaryActor('CEO');

    $this->actingAs($hr)->post(route('hr.salary-changes.approve', $change))->assertForbidden();
    $this->actingAs($hr)->post(route('hr.salary-changes.reject', $change), ['reject_note' => 'Tidak'])->assertForbidden();
    $this->actingAs($ceo)->post(route('hr.salary-changes.store'), salaryPayload(salaryEmployee(['name' => 'Ami'])))->assertForbidden();

    $this->actingAs($ceo)->get(route('hr.salary.index'))
        ->assertInertia(fn (Assert $page) => $page->where('canManage', false)->where('canDecide', true)->has('employees', 0));
    $this->actingAs($hr)->get(route('hr.salary.index'))
        ->assertInertia(fn (Assert $page) => $page->where('canManage', true)->where('canDecide', false)->has('employees', 2));
});

test('no salary route can edit or delete a change', function () {
    $methods = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'hr/salary'))
        ->flatMap(fn ($route) => $route->methods());

    expect($methods->contains('DELETE'))->toBeFalse()
        ->and($methods->contains('PUT'))->toBeFalse()
        ->and($methods->contains('PATCH'))->toBeFalse();
});

// ── Requesting ──────────────────────────────────────────────────────────

test('HR requests a change: old salary snapshot, audited, CEO notified', function () {
    $employee = salaryEmployee();
    $hr = salaryActor('HR');
    $ceo = salaryActor('CEO');

    $this->actingAs($hr)->post(route('hr.salary-changes.store'), salaryPayload($employee))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('hr.salary.index'));

    $change = SalaryChange::sole();
    expect($change->status)->toBe(SalaryChangeStatus::Pending)
        ->and($change->old_salary)->toBe('5000000.00')
        ->and($change->new_salary)->toBe('5500000.00')
        ->and($change->requested_by)->toBe($hr->id)
        ->and($employee->fresh()->base_salary)->toBe('5000000.00');

    expect(AuditLog::where('action', 'hr.salary_change_requested')->sole()->new_values['new_salary'])->toBe('5500000.00');

    $notification = Notification::where('user_id', $ceo->id)->sole();
    expect($notification->type)->toBe('salary_change_requested')
        ->and($notification->metadata['employee_id'])->toBe($employee->id);
});

test('only one open request per employee', function () {
    $employee = salaryEmployee();
    requestSalaryChange($employee);

    $this->actingAs(salaryActor('HR'))->post(route('hr.salary-changes.store'), salaryPayload($employee, ['new_salary' => 6_000_000]))
        ->assertSessionHasErrors(['employee_id' => 'Icha masih punya pengajuan perubahan gaji yang menunggu keputusan CEO.']);

    expect(SalaryChange::count())->toBe(1);
});

test('the new salary must be positive and differ from the current one — Indonesian messages', function () {
    $employee = salaryEmployee();
    $hr = salaryActor('HR');

    $this->actingAs($hr)->post(route('hr.salary-changes.store'), salaryPayload($employee, ['new_salary' => 0, 'reason' => '', 'effective_date' => '']))
        ->assertSessionHasErrors([
            'new_salary' => 'Gaji pokok baru harus lebih dari 0.',
            'reason' => 'Alasan perubahan wajib diisi.',
            'effective_date' => 'Tanggal berlaku wajib diisi.',
        ]);

    $this->actingAs($hr)->post(route('hr.salary-changes.store'), salaryPayload($employee, ['new_salary' => 5_000_000]))
        ->assertSessionHasErrors(['new_salary' => 'Gaji pokok baru sama dengan gaji pokok saat ini (Rp 5.000.000).']);

    expect(SalaryChange::count())->toBe(0);
});

test('inactive and field-staff-linked employees cannot get a request', function () {
    $tukang = salaryActor('FIELD_STAFF');
    $linked = salaryEmployee(['user_id' => $tukang->id]);
    $inactive = salaryEmployee(['name' => 'Hesti', 'is_active' => false]);
    $hr = salaryActor('HR');

    $this->actingAs($hr)->post(route('hr.salary-changes.store'), salaryPayload($linked))
        ->assertSessionHasErrors(['employee_id' => 'Karyawan tidak ditemukan di modul SDM.']);
    $this->actingAs($hr)->post(route('hr.salary-changes.store'), salaryPayload($inactive))
        ->assertSessionHasErrors('employee_id');

    SalaryChange::factory()->create(['employee_id' => $linked->id]);

    $this->actingAs($hr)->get(route('hr.salary.index'))
        ->assertInertia(fn (Assert $page) => $page->has('changes.data', 0)->has('employees', 0));
});

test('a request may reference only that employee\'s review', function () {
    $employee = salaryEmployee();
    $other = salaryEmployee(['name' => 'Ami']);
    $review = salaryReview($employee);

    $this->actingAs(salaryActor('HR'))->post(route('hr.salary-changes.store'), salaryPayload($employee, ['performance_review_id' => salaryReview($other)->id]))
        ->assertSessionHasErrors(['performance_review_id' => 'Evaluasi yang dirujuk bukan milik karyawan ini.']);

    $this->actingAs(salaryActor('HR'))->post(route('hr.salary-changes.store'), salaryPayload($employee, ['performance_review_id' => $review->id]))
        ->assertSessionHasNoErrors();

    expect(SalaryChange::sole()->performance_review_id)->toBe($review->id);
});

// ── Deciding ────────────────────────────────────────────────────────────

test('approving a change effective today updates the base salary at once', function () {
    $employee = salaryEmployee();
    $hr = salaryActor('HR');
    $change = requestSalaryChange($employee, [], $hr);
    $ceo = salaryActor('CEO');

    $this->actingAs($ceo)->post(route('hr.salary-changes.approve', $change))->assertSessionHasNoErrors();

    $change->refresh();
    expect($change->status)->toBe(SalaryChangeStatus::Approved)
        ->and($change->decided_by)->toBe($ceo->id)
        ->and($change->decided_at)->not->toBeNull()
        ->and($change->applied_at)->not->toBeNull()
        ->and($employee->fresh()->base_salary)->toBe('5500000.00');

    $audit = AuditLog::where('action', 'hr.salary_change_approved')->sole();
    expect($audit->old_values['base_salary'])->toBe('5000000.00')
        ->and($audit->new_values['base_salary'])->toBe('5500000.00');

    expect(Notification::where('user_id', $hr->id)->where('type', 'salary_change_decided')->exists())->toBeTrue();

    // A decided change is final.
    $this->actingAs($ceo)->post(route('hr.salary-changes.approve', $change))
        ->assertSessionHasErrors(['status' => 'Pengajuan ini sudah diputuskan sebelumnya.']);
    expect(fn () => $change->update(['new_salary' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $change->update(['applied_at' => now()]))->toThrow(LogicException::class)
        ->and(fn () => $change->delete())->toThrow(LogicException::class);
});

test('a future-dated approval waits for applyDue, which is idempotent', function () {
    $employee = salaryEmployee();
    $change = requestSalaryChange($employee, ['effective_date' => now()->addDays(10)->toDateString()]);

    app(SalaryChangeService::class)->approve($change, salaryActor('CEO'));

    expect($change->fresh()->applied_at)->toBeNull()
        ->and($employee->fresh()->base_salary)->toBe('5000000.00')
        ->and(app(SalaryChangeService::class)->applyDue())->toBe(0);

    // A new request waits until the approved one is applied.
    expect(fn () => requestSalaryChange($employee, ['new_salary' => 7_000_000]))
        ->toThrow(ValidationException::class);

    $this->travel(10)->days();

    (new ApplyDueSalaryChangesJob)->handle(app(SalaryChangeService::class));

    expect($change->fresh()->applied_at)->not->toBeNull()
        ->and($employee->fresh()->base_salary)->toBe('5500000.00')
        ->and(app(SalaryChangeService::class)->applyDue())->toBe(0);

    $audit = AuditLog::where('action', 'hr.salary_change_applied')->sole();
    expect($audit->user_id)->toBeNull()
        ->and($audit->old_values['base_salary'])->toBe('5000000.00');
});

test('rejecting needs a note, is audited and notifies the requester', function () {
    $employee = salaryEmployee();
    $hr = salaryActor('HR');
    $change = requestSalaryChange($employee, [], $hr);
    $ceo = salaryActor('CEO');

    $this->actingAs($ceo)->post(route('hr.salary-changes.reject', $change), ['reject_note' => ''])
        ->assertSessionHasErrors(['reject_note' => 'Alasan penolakan wajib diisi.']);

    $this->actingAs($ceo)->post(route('hr.salary-changes.reject', $change), ['reject_note' => 'Anggaran belum cukup.'])
        ->assertSessionHasNoErrors();

    $change->refresh();
    expect($change->status)->toBe(SalaryChangeStatus::Rejected)
        ->and($change->reject_note)->toBe('Anggaran belum cukup.')
        ->and($employee->fresh()->base_salary)->toBe('5000000.00')
        ->and(AuditLog::where('action', 'hr.salary_change_rejected')->exists())->toBeTrue()
        ->and(Notification::where('user_id', $hr->id)->where('type', 'salary_change_decided')->sole()->title)->toBe('Perubahan gaji ditolak');

    $this->actingAs($ceo)->post(route('hr.salary-changes.approve', $change))->assertSessionHasErrors('status');

    // Rejected → a fresh request is allowed again.
    requestSalaryChange($employee);
    expect(SalaryChange::count())->toBe(2);
});

// ── Page props ──────────────────────────────────────────────────────────

test('the review shortcut prefills the request dialog only for a matching review', function () {
    $employee = salaryEmployee();
    $review = salaryReview($employee);
    $other = salaryReview(salaryEmployee(['name' => 'Ami']));
    $hr = salaryActor('HR');

    $this->actingAs($hr)->get(route('hr.salary.index', ['request_for' => $employee->id, 'review' => $review->id]))
        ->assertInertia(fn (Assert $page) => $page->where('prefill', ['employee_id' => $employee->id, 'performance_review_id' => $review->id]));

    $this->actingAs($hr)->get(route('hr.salary.index', ['request_for' => $employee->id, 'review' => $other->id]))
        ->assertInertia(fn (Assert $page) => $page->where('prefill', ['employee_id' => $employee->id, 'performance_review_id' => null]));

    $this->actingAs($hr)->get(route('hr.salary.index', ['request_for' => 999999]))
        ->assertInertia(fn (Assert $page) => $page->where('prefill', null));
});

test('pending requests come first and the recap groups paid salaries per month and division', function () {
    $employee = salaryEmployee();
    $old = requestSalaryChange($employee);
    app(SalaryChangeService::class)->reject($old, 'Belum waktunya.', salaryActor('CEO'));
    $this->travel(1)->minutes();
    $pending = requestSalaryChange(salaryEmployee(['name' => 'Ami']));

    $period = now()->subMonthNoOverflow()->format('Y-m');
    SalaryPayment::factory()->create(['employee_id' => $employee->id, 'period' => $period, 'base_salary' => 5_000_000, 'allowance' => 0, 'deduction' => 0, 'amount' => 5_000_000]);

    $this->actingAs(salaryActor('CEO'))->get(route('hr.salary.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('changes.data.0.id', $pending->id)
            ->where('summary.pending_count', 1)
            ->where('filters.month', $period)
            ->where('recap.breakdown.total', 5000000)
            ->where('recap.breakdown.by_division.0.name', $employee->position->division->name)
            ->where('recap.breakdown.by_position.0.employees', 1));

    $this->actingAs(salaryActor('CEO'))->get(route('hr.salary.index', ['status' => 'REJECTED']))
        ->assertInertia(fn (Assert $page) => $page->has('changes.data', 1)->where('changes.data.0.id', $old->id));
});

test('the profile tab shows salary history; the self view hides undecided requests', function () {
    $account = salaryActor('DESIGNER');
    $employee = salaryEmployee(['user_id' => $account->id]);
    $approved = requestSalaryChange($employee);
    app(SalaryChangeService::class)->approve($approved, salaryActor('CEO'));
    requestSalaryChange($employee->fresh(), ['new_salary' => 6_000_000]);
    SalaryPayment::factory()->create(['employee_id' => $employee->id, 'period' => now()->format('Y-m'), 'amount' => 5_500_000]);

    $this->actingAs(salaryActor('CEO'))->get(route('hr.employees.show', $employee))
        ->assertInertia(fn (Assert $page) => $page
            ->where('salary.base_salary', '5500000.00')
            ->has('salary.changes', 2)
            ->where('salary.pending.new_salary', '6000000.00')
            ->has('salary.payments', 1)
            ->where('salary.paid_this_year', 5500000)
            ->where('salary.loan_remaining', 0));

    $self = app(SalaryChangeService::class)->forEmployee($employee->fresh(), true);
    expect($self['changes'])->toHaveCount(1)
        ->and($self['pending'])->toBeNull();
});

test('the SDM demo seeder writes discipline and salary samples through the services', function () {
    salaryActor('HR');
    salaryActor('CEO');
    foreach (['Boy', 'Icha', 'Ami', 'Ibnu', 'Hesti', 'Satria'] as $name) {
        salaryEmployee(['name' => $name]);
    }

    $this->seed(DisciplineSalaryDemoSeeder::class);
    $this->seed(DisciplineSalaryDemoSeeder::class); // re-run is a no-op

    expect(DisciplinaryRecord::count())->toBe(5)
        ->and(SalaryChange::pluck('status')->map->value->sort()->values()->all())->toBe(['APPROVED', 'PENDING', 'REJECTED'])
        ->and(Employee::where('name', 'Icha')->value('base_salary'))->toBe('5500000.00');
});
