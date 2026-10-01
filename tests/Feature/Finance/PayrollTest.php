<?php

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Employee;
use App\Models\FinanceTransaction;
use App\Models\SalaryPayment;
use App\Models\Task;
use App\Models\User;
use App\Services\PayrollService;
use App\Services\StaffPaymentService;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function payrollUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function payrollEmployee(array $attributes = []): Employee
{
    return Employee::factory()->create([
        'name' => 'Boy',
        'position' => 'Marketing',
        'base_salary' => 5_000_000,
        'join_date' => '2024-01-15',
        ...$attributes,
    ]);
}

function paySalary(Employee $employee, array $overrides = [], ?User $finance = null): SalaryPayment
{
    return app(PayrollService::class)->pay($employee, [
        'period' => now()->format('Y-m'),
        'paid_at' => now()->toDateString(),
        'bank_account_id' => BankAccount::factory()->create()->id,
        ...$overrides,
    ], $finance ?? payrollUser('FINANCE'));
}

// ── RBAC: salaries are confidential (Sprint 9 decision #7) ──────────────

test('CEO and Finance can open the payroll page', function (string $role) {
    payrollEmployee();

    $this->actingAs(payrollUser($role))->get(route('finance.payroll.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Finance/Payroll/Index')
            ->has('rows', 1)
            ->has('employees', 1));
})->with(['CEO', 'FINANCE']);

test('every other role — PM included — is refused the payroll page', function (string $role) {
    $this->actingAs(payrollUser($role))->get(route('finance.payroll.index'))->assertForbidden();
})->with(['PM', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'QA', 'LOGISTICS', 'FIELD_STAFF']);

test('only Finance can pay salaries and manage employees', function (string $role) {
    $user = payrollUser($role);
    $employee = payrollEmployee();

    $this->actingAs($user)->post(route('finance.payroll.pay'), [
        'employee_id' => $employee->id,
        'period' => now()->format('Y-m'),
        'paid_at' => now()->toDateString(),
        'bank_account_id' => BankAccount::factory()->create()->id,
    ])->assertForbidden();

    $this->actingAs($user)->post(route('finance.employees.store'), [
        'name' => 'Icha',
        'position' => 'Desainer',
        'base_salary' => 4_500_000,
    ])->assertForbidden();

    $this->actingAs($user)->put(route('finance.employees.update', $employee), [
        'name' => 'Boy',
        'position' => 'Marketing',
        'base_salary' => 99_000_000,
        'is_active' => true,
    ])->assertForbidden();

    expect(SalaryPayment::count())->toBe(0)
        ->and(FinanceTransaction::count())->toBe(0)
        ->and(Employee::count())->toBe(1)
        ->and((float) $employee->fresh()->base_salary)->toBe(5_000_000.0);
})->with(['CEO', 'PM', 'LOGISTICS', 'FIELD_STAFF']);

test('the CEO reads the page without payment controls', function () {
    payrollEmployee();
    BankAccount::factory()->create();

    $this->actingAs(payrollUser('CEO'))->get(route('finance.payroll.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canManage', false)
            ->has('bankAccounts', 0)
            ->has('linkableUsers', 0));

    $this->actingAs(payrollUser('FINANCE'))->get(route('finance.payroll.index'))
        ->assertInertia(fn (Assert $page) => $page->where('canManage', true)->has('bankAccounts', 1));
});

test('no payroll or employee route can delete anything', function () {
    $methods = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'finance/payroll') || str_contains($route->uri(), 'finance/employees'))
        ->flatMap(fn ($route) => $route->methods());

    expect($methods->contains('DELETE'))->toBeFalse();
});

// ── Employees ────────────────────────────────────────────────────────────

test('Finance adds an employee, which is audited', function () {
    $finance = payrollUser('FINANCE');
    $account = payrollUser('DESIGNER');

    $this->actingAs($finance)->post(route('finance.employees.store'), [
        'name' => 'Icha',
        'position' => 'Desainer',
        'base_salary' => 4_500_000,
        'user_id' => $account->id,
        'bank_name' => 'BCA',
        'account_no' => '1234567890',
        'join_date' => '2023-05-02',
        'is_active' => false, // new employees always start active
    ])->assertRedirect()->assertSessionHasNoErrors();

    $employee = Employee::sole();
    expect($employee->name)->toBe('Icha')
        ->and($employee->user_id)->toBe($account->id)
        ->and($employee->is_active)->toBeTrue()
        ->and($employee->created_by)->toBe($finance->id)
        ->and((float) $employee->base_salary)->toBe(4_500_000.0);

    $audit = AuditLog::where('action', 'finance.employee_created')->sole();
    expect($audit->model_id)->toBe($employee->id)
        ->and((float) $audit->new_values['base_salary'])->toBe(4_500_000.0);
});

test('employee input is validated with Indonesian messages', function () {
    $finance = payrollUser('FINANCE');

    $this->actingAs($finance)->post(route('finance.employees.store'), ['base_salary' => 0])
        ->assertSessionHasErrors([
            'name' => 'Nama karyawan wajib diisi.',
            'position' => 'Jabatan wajib diisi.',
            'base_salary' => 'Gaji pokok harus lebih dari 0.',
        ]);

    // Field staff are paid per task, never a monthly salary.
    $this->actingAs($finance)->post(route('finance.employees.store'), [
        'name' => 'Rudi',
        'position' => 'Tukang',
        'base_salary' => 3_000_000,
        'user_id' => payrollUser('FIELD_STAFF')->id,
    ])->assertSessionHasErrors('user_id');

    // One employee per account.
    $linked = payrollEmployee(['user_id' => payrollUser('MARKETING')->id]);
    $this->actingAs($finance)->post(route('finance.employees.store'), [
        'name' => 'Boy 2',
        'position' => 'Marketing',
        'base_salary' => 3_000_000,
        'user_id' => $linked->user_id,
    ])->assertSessionHasErrors(['user_id' => 'Akun ini sudah ditautkan ke karyawan lain.']);

    expect(Employee::count())->toBe(1);
});

test('Finance updates a salary and deactivates an employee, both audited', function () {
    $finance = payrollUser('FINANCE');
    $employee = payrollEmployee(['user_id' => payrollUser('MARKETING')->id]);

    $this->actingAs($finance)->put(route('finance.employees.update', $employee), [
        'name' => 'Boy',
        'position' => 'Marketing',
        'base_salary' => 5_500_000,
        'user_id' => $employee->user_id, // keeping its own link is not a duplicate
        'join_date' => '2024-01-15',
        'is_active' => true,
    ])->assertSessionHasNoErrors();

    $audit = AuditLog::where('action', 'finance.employee_updated')->sole();
    expect(array_keys($audit->new_values))->toBe(['base_salary'])
        ->and((float) $audit->old_values['base_salary'])->toBe(5_000_000.0)
        ->and((float) $audit->new_values['base_salary'])->toBe(5_500_000.0);

    $this->actingAs($finance)->put(route('finance.employees.update', $employee), [
        'name' => 'Boy',
        'position' => 'Marketing',
        'base_salary' => 5_500_000,
        'user_id' => $employee->user_id,
        'join_date' => '2024-01-15',
        'is_active' => false,
    ])->assertSessionHasNoErrors();

    expect($employee->fresh()->is_active)->toBeFalse()
        ->and(AuditLog::where('action', 'finance.employee_updated')->latest('id')->first()->new_values)->toBe(['is_active' => false]);
});

test('employees are never deleted', function () {
    $employee = payrollEmployee();

    expect(fn () => $employee->delete())->toThrow(LogicException::class)
        ->and(Employee::whereKey($employee->id)->exists())->toBeTrue();
});

// ── Paying a salary ──────────────────────────────────────────────────────

test('paying a salary writes the salary row, a GAJI_KARYAWAN expense and the audit together', function () {
    $finance = payrollUser('FINANCE');
    $bank = BankAccount::factory()->create();
    $employee = payrollEmployee();
    $period = now()->format('Y-m');

    $this->actingAs($finance)->post(route('finance.payroll.pay'), [
        'employee_id' => $employee->id,
        'period' => $period,
        'allowance' => 750_000,
        'deduction' => 250_000,
        'amount' => 1, // computed server-side — ignored
        'paid_at' => now()->toDateString(),
        'bank_account_id' => $bank->id,
        'note' => 'Bonus target',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $payment = SalaryPayment::sole();
    $transaction = FinanceTransaction::sole();

    expect($payment->employee_id)->toBe($employee->id)
        ->and($payment->period)->toBe($period)
        ->and((float) $payment->base_salary)->toBe(5_000_000.0)
        ->and((float) $payment->allowance)->toBe(750_000.0)
        ->and((float) $payment->deduction)->toBe(250_000.0)
        ->and((float) $payment->amount)->toBe(5_500_000.0)
        ->and($payment->bank_account_id)->toBe($bank->id)
        ->and($payment->finance_transaction_id)->toBe($transaction->id)
        ->and($payment->created_by)->toBe($finance->id)
        ->and($transaction->type)->toBe(FinanceTransactionType::Expense)
        ->and($transaction->kategori)->toBe(FinanceCategory::GajiKaryawan)
        ->and((float) $transaction->amount)->toBe(5_500_000.0)
        ->and($transaction->bank_account_id)->toBe($bank->id)
        ->and($transaction->project_id)->toBeNull()
        ->and($transaction->reference_id)->toBeNull()
        ->and($transaction->description)->toBe('Gaji Boy '.PayrollService::periodLabel($period));

    $audit = AuditLog::where('action', 'finance.salary_paid')->sole();
    expect($audit->model_type)->toBe('SalaryPayment')
        ->and($audit->model_id)->toBe($payment->id)
        ->and($audit->new_values['period'])->toBe($period)
        ->and((float) $audit->new_values['amount'])->toBe(5_500_000.0)
        ->and(AuditLog::where('action', 'finance.transaction_created')->count())->toBe(1);
});

test('the salary snapshot survives a later raise', function () {
    $employee = payrollEmployee();
    $payment = paySalary($employee);

    $employee->update(['base_salary' => 9_000_000]);

    expect((float) $payment->fresh()->base_salary)->toBe(5_000_000.0)
        ->and((float) $payment->fresh()->amount)->toBe(5_000_000.0);
});

test('net pay can be zero but never negative', function () {
    $finance = payrollUser('FINANCE');
    $employee = payrollEmployee();

    $this->actingAs($finance)->post(route('finance.payroll.pay'), [
        'employee_id' => $employee->id,
        'period' => now()->format('Y-m'),
        'allowance' => 100_000,
        'deduction' => 5_100_001,
        'paid_at' => now()->toDateString(),
        'bank_account_id' => BankAccount::factory()->create()->id,
    ])->assertSessionHasErrors(['deduction' => 'Potongan tidak boleh melebihi gaji pokok + tunjangan (Rp 5.100.000).']);

    expect(SalaryPayment::count())->toBe(0)
        ->and(FinanceTransaction::count())->toBe(0)
        ->and(AuditLog::count())->toBe(0);

    $payment = paySalary($employee, ['allowance' => 100_000, 'deduction' => 5_100_000], $finance);
    expect((float) $payment->amount)->toBe(0.0);
});

test('one salary per employee per month', function () {
    $finance = payrollUser('FINANCE');
    $employee = payrollEmployee();
    paySalary($employee, [], $finance);

    $this->actingAs($finance)->post(route('finance.payroll.pay'), [
        'employee_id' => $employee->id,
        'period' => now()->format('Y-m'),
        'paid_at' => now()->toDateString(),
        'bank_account_id' => BankAccount::factory()->create()->id,
    ])->assertSessionHasErrors(['period' => 'Gaji Boy untuk '.PayrollService::periodLabel(now()->format('Y-m')).' sudah dibayar.']);

    expect(SalaryPayment::count())->toBe(1)->and(FinanceTransaction::count())->toBe(1);

    // Another month is a separate salary.
    paySalary($employee, ['period' => now()->subMonthNoOverflow()->format('Y-m')], $finance);
    expect(SalaryPayment::count())->toBe(2);
});

test('a stale second click cannot pay the same month twice', function () {
    $finance = payrollUser('FINANCE');
    $employee = payrollEmployee();
    $staleCopy = Employee::findOrFail($employee->id);

    paySalary($employee, [], $finance);

    expect(fn () => paySalary($staleCopy, [], $finance))->toThrow(ValidationException::class);
    expect(SalaryPayment::count())->toBe(1)->and(FinanceTransaction::count())->toBe(1);
});

test('the database itself refuses a second salary row for the same month', function () {
    $employee = payrollEmployee();
    SalaryPayment::factory()->create(['employee_id' => $employee->id, 'period' => '2026-08']);

    expect(fn () => SalaryPayment::factory()->create(['employee_id' => $employee->id, 'period' => '2026-08']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('inactive employees, future months and months before joining cannot be paid', function () {
    $finance = payrollUser('FINANCE');
    $bank = BankAccount::factory()->create();
    $payload = fn (Employee $employee, string $period) => [
        'employee_id' => $employee->id,
        'period' => $period,
        'paid_at' => now()->toDateString(),
        'bank_account_id' => $bank->id,
    ];

    $inactive = payrollEmployee(['name' => 'Umi', 'is_active' => false]);
    $this->actingAs($finance)->post(route('finance.payroll.pay'), $payload($inactive, now()->format('Y-m')))
        ->assertSessionHasErrors(['employee_id' => 'Karyawan tidak ditemukan atau sudah nonaktif.']);
    expect(fn () => paySalary($inactive, [], $finance))->toThrow(ValidationException::class, 'Umi sudah nonaktif');

    $employee = payrollEmployee(['join_date' => now()->startOfMonth()->toDateString()]);
    $this->actingAs($finance)->post(route('finance.payroll.pay'), $payload($employee, now()->addMonthNoOverflow()->format('Y-m')))
        ->assertSessionHasErrors(['period' => 'Gaji bulan yang belum berjalan belum bisa dibayarkan.']);
    $this->actingAs($finance)->post(route('finance.payroll.pay'), $payload($employee, now()->subMonthNoOverflow()->format('Y-m')))
        ->assertSessionHasErrors('period');
    $this->actingAs($finance)->post(route('finance.payroll.pay'), $payload($employee, '2026-13'))
        ->assertSessionHasErrors(['period' => 'Periode gaji harus berformat TTTT-BB.']);

    expect(SalaryPayment::count())->toBe(0)->and(FinanceTransaction::count())->toBe(0);
});

test('a salary payment needs an active bank account and a date not in the future', function () {
    $finance = payrollUser('FINANCE');
    $employee = payrollEmployee();
    $inactive = BankAccount::factory()->create(['is_active' => false]);

    $this->actingAs($finance)->post(route('finance.payroll.pay'), [
        'employee_id' => $employee->id,
        'period' => now()->format('Y-m'),
        'paid_at' => now()->addDay()->toDateString(),
        'allowance' => -1,
    ])->assertSessionHasErrors([
        'paid_at' => 'Tanggal bayar tidak boleh di masa depan.',
        'bank_account_id' => 'Rekening sumber wajib dipilih.',
        'allowance' => 'Tunjangan tidak boleh negatif.',
    ]);

    $this->actingAs($finance)->post(route('finance.payroll.pay'), [
        'employee_id' => $employee->id,
        'period' => now()->format('Y-m'),
        'paid_at' => now()->toDateString(),
        'bank_account_id' => $inactive->id,
    ])->assertSessionHasErrors(['bank_account_id' => 'Rekening bank tidak ditemukan atau tidak aktif.']);

    expect(fn () => paySalary($employee, ['bank_account_id' => $inactive->id], $finance))->toThrow(ValidationException::class);
    expect(SalaryPayment::count())->toBe(0)->and(FinanceTransaction::count())->toBe(0);
});

test('salary rows are append-only even through Eloquent', function () {
    $payment = paySalary(payrollEmployee());

    expect(fn () => $payment->update(['amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $payment->delete())->toThrow(LogicException::class);
});

// ── The Upah Tukang reference_id rule ────────────────────────────────────

test('paying a salary never marks an unrelated DONE task as paid', function () {
    $finance = payrollUser('FINANCE');
    $employee = payrollEmployee();
    $payment = paySalary($employee, [], $finance);

    // A DONE task whose id collides with every id the salary produced —
    // exactly the row a salary id stored in reference_id would hide.
    $task = Task::factory()->create([
        'id' => max($payment->id, $employee->id, $payment->finance_transaction_id) + 1,
        'status' => TaskStatus::Done->value,
        'rate_per_task' => 350_000,
        'assignee_id' => payrollUser('FIELD_STAFF')->id,
    ]);
    foreach ([$payment->id, $employee->id, $payment->finance_transaction_id] as $id) {
        if (! Task::whereKey($id)->exists()) {
            Task::factory()->create(['id' => $id, 'status' => TaskStatus::Done->value, 'rate_per_task' => 200_000]);
        }
    }

    expect(FinanceTransaction::where('kategori', FinanceCategory::GajiKaryawan->value)->whereNotNull('reference_id')->exists())->toBeFalse();

    foreach (Task::all() as $doneTask) {
        expect(app(StaffPaymentService::class)->isTaskPaid($doneTask))->toBeFalse();
    }

    $this->actingAs($finance)->get(route('finance.staffPayments.index'))
        ->assertInertia(fn (Assert $page) => $page->has('tasks.data', Task::count()));

    // And the wage is still payable — the salary didn't consume it.
    app(StaffPaymentService::class)->pay($task, BankAccount::factory()->create()->id, $finance);
    expect(app(StaffPaymentService::class)->isTaskPaid($task))->toBeTrue();
});

// ── Page content ─────────────────────────────────────────────────────────

test('the page lists who is paid for the selected month', function () {
    $finance = payrollUser('FINANCE');
    $boy = payrollEmployee(['name' => 'Boy', 'base_salary' => 5_000_000]);
    $icha = payrollEmployee(['name' => 'Icha', 'base_salary' => 4_000_000]);
    $umi = payrollEmployee(['name' => 'Umi', 'base_salary' => 3_000_000]);
    $newcomer = payrollEmployee(['name' => 'Satria', 'join_date' => now()->toDateString()]);
    $lastMonth = now()->subMonthNoOverflow()->format('Y-m');

    paySalary($boy, ['allowance' => 500_000], $finance);
    paySalary($umi, ['period' => $lastMonth], $finance);
    $umi->update(['is_active' => false]);

    // Current month by default: Umi (inactive, unpaid) is hidden.
    $this->actingAs($finance)->get(route('finance.payroll.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('period', now()->format('Y-m'))
            ->has('rows', 3)
            ->where('rows.0.employee.name', 'Boy')
            ->where('rows.0.payment.amount', '5500000.00')
            ->where('rows.1.employee.name', 'Icha')
            ->where('rows.1.payment', null)
            ->where('rows.2.employee.name', 'Satria')
            ->where('summary.totalPaid', 5_500_000)
            ->where('summary.paidCount', 1)
            ->where('summary.unpaidCount', 2)
            ->where('summary.unpaidBaseTotal', 9_000_000)
            ->has('employees', 4));

    // Last month: Umi was paid then, so she is listed; Satria hadn't joined.
    $this->actingAs($finance)->get(route('finance.payroll.index', ['period' => $lastMonth]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('period', $lastMonth)
            ->has('rows', 3)
            ->where('rows.2.employee.name', 'Umi')
            ->where('rows.2.payment.amount', '3000000.00')
            ->where('summary.paidCount', 1));

    // A future or malformed period falls back to the current month.
    $this->actingAs($finance)->get(route('finance.payroll.index', ['period' => now()->addMonthsNoOverflow(2)->format('Y-m')]))
        ->assertInertia(fn (Assert $page) => $page->where('period', now()->format('Y-m')));
    $this->actingAs($finance)->get(route('finance.payroll.index', ['period' => 'abc']))
        ->assertInertia(fn (Assert $page) => $page->where('period', now()->format('Y-m')));

    expect($icha->fresh()->salaryPayments()->count())->toBe(0)
        ->and($newcomer->is_active)->toBeTrue();
});

test('the link picker offers active non-field-staff accounts plus current links', function () {
    $finance = payrollUser('FINANCE');
    $designer = payrollUser('DESIGNER');
    $fieldStaff = payrollUser('FIELD_STAFF');
    $inactiveLinked = payrollUser('MARKETING');
    $inactiveLinked->update(['is_active' => false]);
    $inactiveUnlinked = payrollUser('QA');
    $inactiveUnlinked->update(['is_active' => false]);
    payrollEmployee(['user_id' => $inactiveLinked->id]);

    $this->actingAs($finance)->get(route('finance.payroll.index'))
        ->assertInertia(fn (Assert $page) => $page->where('linkableUsers', function ($users) use ($finance, $designer, $fieldStaff, $inactiveLinked, $inactiveUnlinked) {
            $ids = $users->pluck('id');

            return $ids->contains($finance->id)
                && $ids->contains($designer->id)
                && $ids->contains($inactiveLinked->id)
                && ! $ids->contains($fieldStaff->id)
                && ! $ids->contains($inactiveUnlinked->id);
        }));
});
