<?php

use App\Enums\FinanceCategory;
use App\Enums\TaskStatus;
use App\Models\BankAccount;
use App\Models\FinanceTransaction;
use App\Models\Task;
use App\Models\User;
use App\Services\FinanceTransactionService;
use Database\Seeders\RoleSeeder;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(fn () => $this->seed(RoleSeeder::class));

test('roles with read access can view the transaction index', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('finance.transactions.index'))->assertOk();
})->with(['CEO', 'PM', 'FINANCE']);

test('roles without access are forbidden from the transaction index', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('finance.transactions.index'))->assertForbidden();
})->with(['MARKETING', 'DESIGNER', 'ESTIMATOR', 'QA', 'LOGISTICS', 'FIELD_STAFF']);

test('Finance can record a manual transaction with a bank account', function () {
    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');
    $bankAccount = BankAccount::factory()->create();

    $this->actingAs($finance)->post(route('finance.transactions.store'), [
        'bank_account_id' => $bankAccount->id,
        'type' => 'PENGELUARAN',
        'kategori' => 'OPERASIONAL',
        'amount' => 250000,
        'description' => 'Beli alat kebersihan',
        'date' => now()->toDateString(),
    ])->assertRedirect();

    expect(FinanceTransaction::where('description', 'Beli alat kebersihan')->exists())->toBeTrue();
});

test('bank_account_id is required to record a transaction', function () {
    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');

    $this->actingAs($finance)->post(route('finance.transactions.store'), [
        'type' => 'PENGELUARAN',
        'kategori' => 'OPERASIONAL',
        'amount' => 250000,
        'description' => 'Test',
        'date' => now()->toDateString(),
    ])->assertSessionHasErrors('bank_account_id');
});

test('CEO and PM cannot record a transaction', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);
    $bankAccount = BankAccount::factory()->create();

    $this->actingAs($user)->post(route('finance.transactions.store'), [
        'bank_account_id' => $bankAccount->id,
        'type' => 'PENGELUARAN',
        'kategori' => 'OPERASIONAL',
        'amount' => 250000,
        'description' => 'Test',
        'date' => now()->toDateString(),
    ])->assertForbidden();
})->with(['CEO', 'PM']);

test('staff payment list only shows DONE tasks with a rate not yet paid', function () {
    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');
    $donePaidTask = Task::factory()->create(['status' => TaskStatus::Done->value, 'rate_per_task' => 100000]);
    FinanceTransaction::factory()->create([
        'reference_id' => $donePaidTask->id,
        'kategori' => FinanceCategory::GajiKaryawan->value,
    ]);
    $doneUnpaidTask = Task::factory()->create(['status' => TaskStatus::Done->value, 'rate_per_task' => 150000]);
    Task::factory()->create(['status' => TaskStatus::OnProgress->value, 'rate_per_task' => 150000]);

    $response = $this->actingAs($finance)->get(route('finance.staffPayments.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->has('tasks.data', 1)
        ->where('tasks.data.0.id', $doneUnpaidTask->id)
    );
});

test('Finance can pay a DONE task once, and not twice', function () {
    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');
    $task = Task::factory()->create(['status' => TaskStatus::Done->value, 'rate_per_task' => 150000]);

    $this->actingAs($finance)->post(route('finance.staffPayments.pay', ['task' => $task->id]))->assertRedirect();

    expect(FinanceTransaction::where('reference_id', $task->id)->where('kategori', 'GAJI_KARYAWAN')->exists())->toBeTrue();

    expect(fn () => app(FinanceTransactionService::class)->payStaffForTask($task, $finance))
        ->toThrow(ValidationException::class);
});

test('cash flow dashboard is reachable by CEO/PM/FINANCE', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');

    $this->actingAs($ceo)->get(route('finance.dashboard'))->assertOk();
});

test('cash flow Excel export streams a CashFlowExport download', function () {
    Excel::fake();

    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');

    $this->actingAs($ceo)->get(route('finance.transactions.export'))->assertOk();

    Excel::assertDownloaded('cash-flow-'.now()->format('Y-m').'.xlsx');
});
