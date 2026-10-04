<?php

use App\Models\AuditLog;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Services\UserService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function hrUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function hrEmployeePayload(array $overrides = []): array
{
    return [
        'name' => 'Icha',
        'position_id' => Position::factory()->create()->id,
        'base_salary' => 4_500_000,
        'bank_name' => 'BCA',
        'account_no' => '1234567890',
        'join_date' => '2023-05-02',
        ...$overrides,
    ];
}

// ── RBAC: module:hr = CEO reads, HR manages (Sprint 10 decision #2) ─────

test('CEO and HR can open the SDM employee and structure pages', function (string $role) {
    Employee::factory()->create();

    $this->actingAs(hrUser($role))->get(route('hr.employees.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('HR/Employees/Index')->has('employees', 1));

    $this->actingAs(hrUser($role))->get(route('hr.structure.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('HR/Structure/Index'));
})->with(['CEO', 'HR', 'SUPERADMIN']);

test('every other role is refused the SDM module', function (string $role) {
    $employee = Employee::factory()->create();
    $user = hrUser($role);

    $this->actingAs($user)->get(route('hr.employees.index'))->assertForbidden();
    $this->actingAs($user)->get(route('hr.employees.show', $employee))->assertForbidden();
    $this->actingAs($user)->get(route('hr.structure.index'))->assertForbidden();
    $this->actingAs($user)->post(route('hr.employees.store'), hrEmployeePayload())->assertForbidden();
})->with(['FINANCE', 'PM', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'QA', 'LOGISTICS', 'FIELD_STAFF']);

test('only HR writes — the CEO reads without management controls', function () {
    $ceo = hrUser('CEO');
    $employee = Employee::factory()->create();

    $this->actingAs($ceo)->post(route('hr.employees.store'), hrEmployeePayload())->assertForbidden();
    $this->actingAs($ceo)->put(route('hr.employees.update', $employee), ['name' => 'X'])->assertForbidden();
    $this->actingAs($ceo)->post(route('hr.divisions.store'), ['name' => 'Baru'])->assertForbidden();

    $this->actingAs($ceo)->get(route('hr.employees.index'))
        ->assertInertia(fn (Assert $page) => $page->where('canManage', false)->has('linkableUsers', 0));
    $this->actingAs(hrUser('HR'))->get(route('hr.employees.index'))
        ->assertInertia(fn (Assert $page) => $page->where('canManage', true));
});

test('no employee route can delete an employee', function () {
    $methods = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'hr/employees'))
        ->flatMap(fn ($route) => $route->methods());

    expect($methods->contains('DELETE'))->toBeFalse();
});

// ── Employees ───────────────────────────────────────────────────────────

test('HR adds an employee on a structured position, which is audited', function () {
    $hr = hrUser('HR');
    $account = hrUser('DESIGNER');
    $payload = hrEmployeePayload(['user_id' => $account->id, 'is_active' => false]);

    $this->actingAs($hr)->post(route('hr.employees.store'), $payload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $employee = Employee::sole();
    expect($employee->position_id)->toBe($payload['position_id'])
        ->and($employee->user_id)->toBe($account->id)
        ->and($employee->is_active)->toBeTrue() // new employees always start active
        ->and($employee->created_by)->toBe($hr->id)
        ->and((float) $employee->base_salary)->toBe(4_500_000.0);

    $audit = AuditLog::where('action', 'hr.employee_created')->sole();
    expect($audit->model_id)->toBe($employee->id)
        ->and($audit->new_values['position_id'])->toBe($payload['position_id']);
});

test('employee input is validated with Indonesian messages', function () {
    $hr = hrUser('HR');

    $this->actingAs($hr)->post(route('hr.employees.store'), ['base_salary' => 0])
        ->assertSessionHasErrors([
            'name' => 'Nama karyawan wajib diisi.',
            'position_id' => 'Jabatan wajib dipilih.',
            'base_salary' => 'Gaji pokok harus lebih dari 0.',
        ]);

    // No free-text job titles any more.
    $this->actingAs($hr)->post(route('hr.employees.store'), hrEmployeePayload(['position_id' => 99999]))
        ->assertSessionHasErrors(['position_id' => 'Jabatan tidak ditemukan.']);

    // An inactive position (or one in an inactive division) can't be newly assigned.
    $this->actingAs($hr)->post(route('hr.employees.store'), hrEmployeePayload(['position_id' => Position::factory()->inactive()->create()->id]))
        ->assertSessionHasErrors(['position_id' => 'Jabatan ini sudah nonaktif — pilih jabatan lain.']);
    $inInactiveDivision = Position::factory()->for(Division::factory()->inactive())->create();
    $this->actingAs($hr)->post(route('hr.employees.store'), hrEmployeePayload(['position_id' => $inInactiveDivision->id]))
        ->assertSessionHasErrors('position_id');

    // One employee per account.
    $linked = Employee::factory()->create(['user_id' => hrUser('MARKETING')->id]);
    $this->actingAs($hr)->post(route('hr.employees.store'), hrEmployeePayload(['user_id' => $linked->user_id]))
        ->assertSessionHasErrors(['user_id' => 'Akun ini sudah ditautkan ke karyawan lain.']);

    expect(Employee::count())->toBe(1);
});

test('an employee keeps an inactive position they already hold', function () {
    $employee = Employee::factory()->create();
    $employee->position->update(['is_active' => false]);

    $this->actingAs(hrUser('HR'))->put(route('hr.employees.update', $employee), [
        'name' => 'Nama Baru',
        'position_id' => $employee->position_id,
        'is_active' => true,
    ])->assertSessionHasNoErrors();

    expect($employee->fresh()->name)->toBe('Nama Baru');
});

test('editing an employee never changes the base salary (decision #3)', function () {
    $employee = Employee::factory()->create(['base_salary' => 5_000_000]);
    $newPosition = Position::factory()->create();

    $this->actingAs(hrUser('HR'))->put(route('hr.employees.update', $employee), [
        'name' => $employee->name,
        'position_id' => $newPosition->id,
        'base_salary' => 99_000_000,
        'is_active' => false,
    ])->assertSessionHasNoErrors();

    $fresh = $employee->fresh();
    expect((float) $fresh->base_salary)->toBe(5_000_000.0)
        ->and($fresh->position_id)->toBe($newPosition->id)
        ->and($fresh->is_active)->toBeFalse();

    $audit = AuditLog::where('action', 'hr.employee_updated')->sole();
    expect(array_keys($audit->new_values))->toEqualCanonicalizing(['position_id', 'is_active']);
});

test('the profile shows the employee with every tab', function () {
    $employee = Employee::factory()->create();

    $this->actingAs(hrUser('CEO'))->get(route('hr.employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('HR/Employees/Show')
            ->where('employee.id', $employee->id)
            ->has('discipline')
            ->has('salary')
            ->has('kpi')
            ->has('reviews')
            ->where('canManage', false)
            ->where('canDecideSalary', true));
});

test('the list filters by division, position, status and name', function () {
    $designPosition = Position::factory()->create();
    $otherPosition = Position::factory()->create();
    $icha = Employee::factory()->create(['name' => 'Icha', 'position_id' => $designPosition->id]);
    Employee::factory()->create(['name' => 'Boy', 'position_id' => $otherPosition->id]);
    Employee::factory()->inactive()->create(['name' => 'Lama', 'position_id' => $designPosition->id]);
    $hr = hrUser('HR');

    $ids = fn (array $query) => collect($this->actingAs($hr)->get(route('hr.employees.index', $query))->viewData('page')['props']['employees'])->pluck('name')->sort()->values()->all();

    expect($ids(['division' => $designPosition->division_id]))->toBe(['Icha', 'Lama'])
        ->and($ids(['position' => $otherPosition->id]))->toBe(['Boy'])
        ->and($ids(['division' => $designPosition->division_id, 'status' => 'active']))->toBe(['Icha'])
        ->and($ids(['search' => 'ich']))->toBe([$icha->name]);
});

// ── Field staff are kept out of SDM (decision #11) ──────────────────────

test('a field-staff account can never be linked to an employee', function () {
    $this->actingAs(hrUser('HR'))->post(route('hr.employees.store'), hrEmployeePayload(['user_id' => hrUser('FIELD_STAFF')->id]))
        ->assertSessionHasErrors(['user_id' => 'Akun tukang tidak bisa ditautkan ke data karyawan — tukang dibayar lewat Upah Tukang per task.']);

    expect(Employee::count())->toBe(0);
});

test('an employee linked to a field-staff account never shows up in SDM', function () {
    $visible = Employee::factory()->create(['user_id' => hrUser('DESIGNER')->id]);
    $unlinked = Employee::factory()->create(['user_id' => null]);
    // Defensive layer: even if the link existed (data from before the rule).
    $hidden = Employee::factory()->create(['user_id' => hrUser('FIELD_STAFF')->id]);

    expect(Employee::query()->hrEligible()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$visible->id, $unlinked->id])->sort()->values()->all());

    $hr = hrUser('HR');
    $this->actingAs($hr)->get(route('hr.employees.index'))
        ->assertInertia(fn (Assert $page) => $page->has('employees', 2));
    $this->actingAs($hr)->get(route('hr.employees.show', $hidden))->assertNotFound();
});

test('the account picker never offers field staff', function () {
    $fieldStaff = hrUser('FIELD_STAFF');
    $designer = hrUser('DESIGNER');

    $this->actingAs(hrUser('HR'))->get(route('hr.employees.index'))
        ->assertInertia(fn (Assert $page) => $page->where('linkableUsers', fn ($users) => $users->pluck('id')->contains($designer->id)
            && ! $users->pluck('id')->contains($fieldStaff->id)));
});

test('an account linked to an employee cannot be turned into field staff', function () {
    $user = hrUser('DESIGNER');
    $employee = Employee::factory()->create(['name' => 'Icha', 'user_id' => $user->id]);
    $payload = ['name' => $user->name, 'email' => $user->email, 'role' => 'FIELD_STAFF'];

    expect(fn () => app(UserService::class)->update($user, $payload))
        ->toThrow(ValidationException::class);
    expect($user->fresh()->hasRole('FIELD_STAFF'))->toBeFalse();

    // Once HR unlinks it, the role change goes through.
    $employee->update(['user_id' => null]);
    app(UserService::class)->update($user, $payload);
    expect($user->fresh()->hasRole('FIELD_STAFF'))->toBeTrue();
});

// ── Divisi & Jabatan (decision #10) ─────────────────────────────────────

test('HR manages divisions and positions, all audited', function () {
    $hr = hrUser('HR');

    $this->actingAs($hr)->post(route('hr.divisions.store'), ['name' => 'Desain', 'sort_order' => 1])->assertSessionHasNoErrors();
    $division = Division::where('name', 'Desain')->sole();

    $this->actingAs($hr)->post(route('hr.positions.store'), ['division_id' => $division->id, 'name' => 'Arsitek'])->assertSessionHasNoErrors();
    $position = Position::where('name', 'Arsitek')->sole();

    $this->actingAs($hr)->put(route('hr.positions.update', $position), [
        'division_id' => $division->id,
        'name' => 'Arsitek Senior',
        'is_active' => false,
    ])->assertSessionHasNoErrors();

    expect($position->fresh()->name)->toBe('Arsitek Senior')
        ->and($position->fresh()->is_active)->toBeFalse()
        ->and(AuditLog::whereIn('action', ['hr.division_created', 'hr.position_created', 'hr.position_updated'])->count())->toBe(3);
});

test('position names are unique per division, not globally', function () {
    $hr = hrUser('HR');
    $design = Division::factory()->create();
    $project = Division::factory()->create();
    Position::factory()->create(['division_id' => $design->id, 'name' => 'Admin']);

    $this->actingAs($hr)->post(route('hr.positions.store'), ['division_id' => $design->id, 'name' => 'Admin'])
        ->assertSessionHasErrors(['name' => 'Jabatan ini sudah ada di divisi tersebut.']);
    $this->actingAs($hr)->post(route('hr.positions.store'), ['division_id' => $project->id, 'name' => 'Admin'])
        ->assertSessionHasNoErrors();

    $this->actingAs($hr)->post(route('hr.divisions.store'), ['name' => $design->name])
        ->assertSessionHasErrors(['name' => 'Divisi ini sudah ada.']);
});

test('a used division or position is deactivated, not deleted', function () {
    $hr = hrUser('HR');
    $employee = Employee::factory()->create();
    $position = $employee->position;

    $this->actingAs($hr)->delete(route('hr.positions.destroy', $position))->assertSessionHasErrors('position');
    $this->actingAs($hr)->delete(route('hr.divisions.destroy', $position->division))->assertSessionHasErrors('division');
    expect(Position::whereKey($position->id)->exists())->toBeTrue();

    $unused = Position::factory()->create();
    $this->actingAs($hr)->delete(route('hr.positions.destroy', $unused))->assertSessionHasNoErrors();
    $this->actingAs($hr)->delete(route('hr.divisions.destroy', $unused->division))->assertSessionHasNoErrors();
    expect(Position::whereKey($unused->id)->exists())->toBeFalse()
        ->and(Division::whereKey($unused->division_id)->exists())->toBeFalse();
});
