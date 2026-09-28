<?php

use App\Enums\TaskStatus;
use App\Models\BankAccount;
use App\Models\FinanceTransaction;
use App\Models\StaffLoan;
use App\Models\Task;
use App\Models\User;
use App\Services\StaffLoanService;
use App\Services\StaffPaymentService;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function staffPaymentUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function staffWithLoan(float $amount, float $installment, BankAccount $bank, User $finance): array
{
    $staff = staffPaymentUser('FIELD_STAFF');
    $loan = app(StaffLoanService::class)->create([
        'staff_id' => $staff->id,
        'amount' => $amount,
        'installment_amount' => $installment,
        'bank_account_id' => $bank->id,
    ], $finance);

    return [$staff, $loan];
}

test('paying a wage deducts the loan installment and books gross wage + loan income on the same account', function () {
    $finance = staffPaymentUser('FINANCE');
    $bank = BankAccount::factory()->create();
    [$staff, $loan] = staffWithLoan(1_000_000, 200_000, $bank, $finance);
    $task = Task::factory()->create(['assignee_id' => $staff->id, 'status' => TaskStatus::Done->value, 'rate_per_task' => 500_000]);

    $this->actingAs($finance)->post(route('finance.staffPayments.pay', ['task' => $task->id]), [
        'bank_account_id' => $bank->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $wage = FinanceTransaction::where('kategori', 'GAJI_KARYAWAN')->sole();
    $installment = FinanceTransaction::where('kategori', 'PINJAMAN')->where('type', 'PEMASUKAN')->sole();

    expect((float) $wage->amount)->toBe(500_000.0)
        ->and($installment->bank_account_id)->toBe($bank->id)
        ->and((float) $installment->amount)->toBe(200_000.0)
        ->and((float) $loan->refresh()->remaining)->toBe(800_000.0)
        ->and($loan->payments()->sole()->task_id)->toBe($task->id);
});

test('a wage smaller than the installment is fully deducted and the loan never goes negative', function () {
    $finance = staffPaymentUser('FINANCE');
    $bank = BankAccount::factory()->create();
    [$staff, $loan] = staffWithLoan(100_000, 300_000, $bank, $finance);
    $task = Task::factory()->create(['assignee_id' => $staff->id, 'status' => TaskStatus::Done->value, 'rate_per_task' => 150_000]);

    expect(app(StaffPaymentService::class)->preview($task))->toBe(['wage' => 150_000.0, 'deduction' => 100_000.0, 'net' => 50_000.0]);

    app(StaffPaymentService::class)->pay($task, $bank->id, $finance);

    expect($loan->refresh()->status)->toBe('LUNAS')
        ->and((float) $loan->remaining)->toBe(0.0);
});

test('the staff payments page shows the deduction preview and active bank accounts', function () {
    $finance = staffPaymentUser('FINANCE');
    $bank = BankAccount::factory()->create();
    BankAccount::factory()->create(['is_active' => false]);
    [$staff] = staffWithLoan(1_000_000, 250_000, $bank, $finance);
    Task::factory()->create(['assignee_id' => $staff->id, 'status' => TaskStatus::Done->value, 'rate_per_task' => 400_000]);

    $this->actingAs($finance)->get(route('finance.staffPayments.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('bankAccounts', 1)
            ->where('tasks.data.0.payment_preview.deduction', 250_000)
            ->where('tasks.data.0.payment_preview.net', 150_000));
});

test('paying a wage requires an active bank account', function () {
    $finance = staffPaymentUser('FINANCE');
    $task = Task::factory()->create(['status' => TaskStatus::Done->value, 'rate_per_task' => 100_000]);

    $this->actingAs($finance)->post(route('finance.staffPayments.pay', ['task' => $task->id]))
        ->assertSessionHasErrors('bank_account_id');

    expect(FinanceTransaction::count())->toBe(0)
        ->and(StaffLoan::count())->toBe(0);
});

test('only Finance can pay a wage', function (string $role) {
    $task = Task::factory()->create(['status' => TaskStatus::Done->value, 'rate_per_task' => 100_000]);

    $this->actingAs(staffPaymentUser($role))->post(route('finance.staffPayments.pay', ['task' => $task->id]), [
        'bank_account_id' => BankAccount::factory()->create()->id,
    ])->assertForbidden();
})->with(['CEO', 'PM', 'FIELD_STAFF']);
