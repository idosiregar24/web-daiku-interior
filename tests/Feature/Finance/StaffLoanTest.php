<?php

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\FinanceTransaction;
use App\Models\StaffLoan;
use App\Models\Task;
use App\Models\User;
use App\Services\StaffLoanService;
use Database\Seeders\RoleSeeder;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function staffLoanUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function makeStaffLoan(User $staff, float $amount, float $installment, ?User $actor = null): StaffLoan
{
    return app(StaffLoanService::class)->create([
        'staff_id' => $staff->id,
        'amount' => $amount,
        'installment_amount' => $installment,
        'bank_account_id' => BankAccount::factory()->create()->id,
        'description' => 'Kasbon',
    ], $actor ?? staffLoanUser('FINANCE'));
}

test('CEO, PM and FINANCE can view the staff loan index and detail', function (string $role) {
    $loan = makeStaffLoan(staffLoanUser('FIELD_STAFF'), 1_000_000, 200_000);
    $user = staffLoanUser($role);

    $this->actingAs($user)->get(route('finance.staffLoans.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Finance/StaffLoans/Index')
            ->has('loans.data', 1)
            ->where('loans.data.0.staff.id', $loan->staff_id)
        );

    $this->actingAs($user)->get(route('finance.staffLoans.show', $loan))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Finance/StaffLoans/Show')
            ->where('loan.id', $loan->id)
            ->where('loan.bank_account.id', $loan->bank_account_id)
        );
})->with(['CEO', 'PM', 'FINANCE']);

test('roles outside Finance – Transaction are forbidden from the staff loan index', function (string $role) {
    $this->actingAs(staffLoanUser($role))->get(route('finance.staffLoans.index'))->assertForbidden();
})->with(['FIELD_STAFF', 'MARKETING', 'DESIGNER', 'QA', 'LOGISTICS']);

test('only FINANCE can open the create form', function () {
    $this->actingAs(staffLoanUser('FINANCE'))->get(route('finance.staffLoans.create'))->assertOk();
    $this->actingAs(staffLoanUser('CEO'))->get(route('finance.staffLoans.create'))->assertForbidden();
});

test('FINANCE creates a loan, which writes a PINJAMAN expense with the bank account and an audit row', function () {
    $finance = staffLoanUser('FINANCE');
    $staff = staffLoanUser('FIELD_STAFF');
    $bankAccount = BankAccount::factory()->create();

    $this->actingAs($finance)->post(route('finance.staffLoans.store'), [
        'staff_id' => $staff->id,
        'amount' => 1_500_000,
        'installment_amount' => 300_000,
        'bank_account_id' => $bankAccount->id,
        'description' => 'Biaya berobat',
    ])->assertRedirect();

    $loan = StaffLoan::sole();
    expect((float) $loan->remaining)->toBe(1_500_000.0)
        ->and($loan->status)->toBe('BERJALAN')
        ->and($loan->created_by)->toBe($finance->id);

    $transaction = FinanceTransaction::sole();
    expect($transaction->type)->toBe(FinanceTransactionType::Expense)
        ->and($transaction->kategori)->toBe(FinanceCategory::Pinjaman)
        ->and($transaction->bank_account_id)->toBe($bankAccount->id)
        ->and((int) $transaction->reference_id)->toBe($loan->id)
        ->and((float) $transaction->amount)->toBe(1_500_000.0);

    expect(AuditLog::where('action', 'finance.staff_loan_created')->where('model_id', $loan->id)->exists())->toBeTrue();
});

test('store validates staff role, bank account and installment ceiling', function () {
    $finance = staffLoanUser('FINANCE');
    $inactiveBank = BankAccount::factory()->create(['is_active' => false]);

    $this->actingAs($finance)->post(route('finance.staffLoans.store'), [
        'staff_id' => staffLoanUser('MARKETING')->id,
        'amount' => 1_000_000,
        'installment_amount' => 2_000_000,
        'bank_account_id' => $inactiveBank->id,
    ])->assertSessionHasErrors(['staff_id', 'installment_amount', 'bank_account_id']);

    $this->actingAs($finance)->post(route('finance.staffLoans.store'), [
        'staff_id' => staffLoanUser('FIELD_STAFF')->id,
        'amount' => 1_000_000,
        'installment_amount' => 100_000,
    ])->assertSessionHasErrors('bank_account_id');

    expect(StaffLoan::count())->toBe(0);
});

test('CEO and PM cannot create loans or record payments', function (string $role) {
    $loan = makeStaffLoan(staffLoanUser('FIELD_STAFF'), 1_000_000, 200_000);
    $user = staffLoanUser($role);

    $this->actingAs($user)->post(route('finance.staffLoans.store'), [
        'staff_id' => $loan->staff_id,
        'amount' => 500_000,
        'installment_amount' => 100_000,
        'bank_account_id' => $loan->bank_account_id,
    ])->assertForbidden();

    $this->actingAs($user)->post(route('finance.staffLoans.storePayment', $loan), [
        'amount' => 100_000,
        'paid_date' => now()->toDateString(),
        'bank_account_id' => $loan->bank_account_id,
    ])->assertForbidden();
})->with(['CEO', 'PM']);

test('FINANCE records a manual payment, updating paid_amount and remaining', function () {
    $finance = staffLoanUser('FINANCE');
    $loan = makeStaffLoan(staffLoanUser('FIELD_STAFF'), 1_000_000, 200_000);

    $this->actingAs($finance)->post(route('finance.staffLoans.storePayment', $loan), [
        'amount' => 400_000,
        'paid_date' => now()->toDateString(),
        'note' => 'Bayar tunai',
        'bank_account_id' => $loan->bank_account_id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $loan->refresh();
    expect((float) $loan->paid_amount)->toBe(400_000.0)
        ->and((float) $loan->remaining)->toBe(600_000.0)
        ->and($loan->payments()->count())->toBe(1)
        ->and($loan->payments()->first()->created_by)->toBe($finance->id);

    expect(AuditLog::where('action', 'finance.staff_loan_payment')->exists())->toBeTrue();

    // Cash coming back is PINJAMAN income on the chosen account.
    $income = FinanceTransaction::where('type', 'PEMASUKAN')->where('kategori', 'PINJAMAN')->sole();
    expect((float) $income->amount)->toBe(400_000.0)
        ->and($income->bank_account_id)->toBe($loan->bank_account_id)
        ->and($income->reference_id)->toBe($loan->id);
});

test('a manual payment requires an active bank account', function () {
    $loan = makeStaffLoan(staffLoanUser('FIELD_STAFF'), 500_000, 100_000);
    $inactive = BankAccount::factory()->create(['is_active' => false]);

    $this->actingAs(staffLoanUser('FINANCE'))->post(route('finance.staffLoans.storePayment', $loan), [
        'amount' => 100_000,
        'paid_date' => now()->toDateString(),
    ])->assertSessionHasErrors('bank_account_id');

    $this->actingAs(staffLoanUser('FINANCE'))->post(route('finance.staffLoans.storePayment', $loan), [
        'amount' => 100_000,
        'paid_date' => now()->toDateString(),
        'bank_account_id' => $inactive->id,
    ])->assertSessionHasErrors('bank_account_id');

    expect($loan->payments()->count())->toBe(0);
});

test('a payment larger than the remaining balance is rejected', function () {
    $finance = staffLoanUser('FINANCE');
    $loan = makeStaffLoan(staffLoanUser('FIELD_STAFF'), 500_000, 100_000);

    $this->actingAs($finance)->post(route('finance.staffLoans.storePayment', $loan), [
        'amount' => 600_000,
        'paid_date' => now()->toDateString(),
        'bank_account_id' => $loan->bank_account_id,
    ])->assertSessionHasErrors('amount');

    expect(fn () => app(StaffLoanService::class)->recordPayment($loan, ['amount' => 0], $finance))
        ->toThrow(ValidationException::class);

    $loan->refresh();
    expect((float) $loan->paid_amount)->toBe(0.0)
        ->and($loan->payments()->count())->toBe(0);
});

test('paying off the full remaining marks the loan LUNAS and filters by status', function () {
    $finance = staffLoanUser('FINANCE');
    $paidOff = makeStaffLoan(staffLoanUser('FIELD_STAFF'), 300_000, 100_000);
    makeStaffLoan(staffLoanUser('FIELD_STAFF'), 800_000, 100_000);

    app(StaffLoanService::class)->recordPayment($paidOff, ['amount' => 300_000, 'paid_date' => now()->toDateString(), 'bank_account_id' => $paidOff->bank_account_id], $finance);

    expect($paidOff->refresh()->status)->toBe('LUNAS')
        ->and((float) $paidOff->remaining)->toBe(0.0);

    $this->actingAs($finance)->get(route('finance.staffLoans.index', ['status' => 'LUNAS']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('loans.data', 1)
            ->where('loans.data.0.id', $paidOff->id)
            ->where('summary.ongoingCount', 0)
        );

    $this->actingAs($finance)->get(route('finance.staffLoans.index', ['status' => 'BERJALAN']))
        ->assertInertia(fn (Assert $page) => $page->has('loans.data', 1)->where('summary.ongoingCount', 1));
});

test('deductFromWage walks loans oldest first, capped by installment, remaining and wage', function () {
    $finance = staffLoanUser('FINANCE');
    $staff = staffLoanUser('FIELD_STAFF');
    $otherStaff = staffLoanUser('FIELD_STAFF');

    $this->travel(-2)->days();
    $oldest = makeStaffLoan($staff, 400_000, 400_000, $finance);
    // Leaves 150k — less than the 400k installment, so `remaining` is the cap.
    app(StaffLoanService::class)->recordPayment($oldest, ['amount' => 250_000, 'bank_account_id' => $oldest->bank_account_id], $finance);
    $this->travelBack();
    $this->travel(-1)->days();
    $middle = makeStaffLoan($staff, 1_000_000, 300_000, $finance);
    $this->travelBack();
    $newest = makeStaffLoan($staff, 1_000_000, 300_000, $finance);
    $untouched = makeStaffLoan($otherStaff, 1_000_000, 300_000, $finance);

    $service = app(StaffLoanService::class);
    $task = Task::factory()->create(['assignee_id' => $staff->id]);
    $bankId = BankAccount::factory()->create()->id;

    // The read-only preview must match what is then actually deducted.
    expect($service->previewDeduction($staff, 600_000))->toBe(600_000.0);

    // Wage 600k: oldest takes 150k (paid off), middle 300k, newest only the 150k left.
    $deducted = $service->deductFromWage($staff, 600_000, $task, $bankId, $finance);

    expect($deducted)->toBe(600_000.0)
        ->and($oldest->refresh()->status)->toBe('LUNAS')
        ->and((float) $middle->refresh()->remaining)->toBe(700_000.0)
        ->and((float) $newest->refresh()->remaining)->toBe(850_000.0)
        ->and((float) $untouched->refresh()->paid_amount)->toBe(0.0)
        ->and($middle->payments()->first()->task_id)->toBe($task->id);

    // Next wage larger than all installments: paid-off loan skipped, each other loan capped at 300k.
    expect($service->previewDeduction($staff, 5_000_000))->toBe(600_000.0);
    $deducted = $service->deductFromWage($staff, 5_000_000, $task, $bankId, $finance);

    expect($deducted)->toBe(600_000.0)
        ->and($oldest->payments()->count())->toBe(2) // manual prepayment + first deduction only
        ->and((float) $middle->refresh()->remaining)->toBe(400_000.0)
        ->and((float) $newest->refresh()->remaining)->toBe(550_000.0);
});

test('deductFromWage returns zero for a staff member without outstanding loans', function () {
    $staff = staffLoanUser('FIELD_STAFF');
    $task = Task::factory()->create(['assignee_id' => $staff->id]);

    expect(app(StaffLoanService::class)->deductFromWage($staff, 500_000, $task, BankAccount::factory()->create()->id, staffLoanUser('FINANCE')))->toBe(0.0)
        ->and(app(StaffLoanService::class)->previewDeduction($staff, 500_000))->toBe(0.0);
});
