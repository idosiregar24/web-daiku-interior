<?php

use App\Enums\FinanceCategory;
use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\FinanceTransaction;
use App\Models\Task;
use App\Models\User;
use App\Services\StaffPaymentService;
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

test('categories owned by a dedicated flow cannot be recorded manually', function (string $kategori, string $type) {
    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');

    $this->actingAs($finance)->post(route('finance.transactions.store'), [
        'bank_account_id' => BankAccount::factory()->create()->id,
        'type' => $type,
        'kategori' => $kategori,
        'amount' => 250000,
        'description' => 'Coba kategori sistem',
        'date' => now()->toDateString(),
    ])->assertSessionHasErrors('kategori');

    expect(FinanceTransaction::count())->toBe(0);
})->with([
    ['PINJAMAN', 'PENGELUARAN'],
    ['HUTANG_IDEAL', 'PENGELUARAN'],
    ['DOWN_PAYMENT', 'PEMASUKAN'],
    ['TERMIN', 'PEMASUKAN'],
    // Sprint 9: one-legged transfers / wages outside their flows.
    ['PINDAH_DANA', 'PEMASUKAN'],
    ['PINDAH_DANA', 'PENGELUARAN'],
    ['GAJI_KARYAWAN', 'PENGELUARAN'],
]);

test('a manual transaction needs an active bank account', function () {
    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');

    $this->actingAs($finance)->post(route('finance.transactions.store'), [
        'bank_account_id' => BankAccount::factory()->create(['is_active' => false])->id,
        'type' => 'PENGELUARAN',
        'kategori' => 'OPERASIONAL',
        'amount' => 250000,
        'description' => 'Rekening lama',
        'date' => now()->toDateString(),
    ])->assertSessionHasErrors(['bank_account_id' => 'Rekening bank tidak valid atau tidak aktif.']);
});

test('the transaction list filters by account and kategori and sends the system-managed list', function () {
    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');
    $bca = BankAccount::factory()->create();
    $mandiri = BankAccount::factory()->create();
    FinanceTransaction::factory()->create(['bank_account_id' => $bca->id, 'kategori' => 'OPERASIONAL']);
    FinanceTransaction::factory()->create(['bank_account_id' => $bca->id, 'kategori' => 'BBM']);
    FinanceTransaction::factory()->create(['bank_account_id' => $mandiri->id, 'kategori' => 'BBM']);

    $this->actingAs($finance)
        ->get(route('finance.transactions.index', ['bank_account_id' => $bca->id, 'kategori' => 'BBM']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('transactions.data', 1)
            ->where('transactions.data.0.bank_account_id', $bca->id)
            ->where('filters.kategori', 'BBM')
            ->has('bankAccounts', 2)
            ->where('systemManagedCategories', fn ($categories) => collect($categories)->contains('PINDAH_DANA')
                && collect($categories)->contains('GAJI_KARYAWAN')));
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

    $bank = BankAccount::factory()->create();

    $this->actingAs($finance)->post(route('finance.staffPayments.pay', ['task' => $task->id]), [
        'bank_account_id' => $bank->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $wage = FinanceTransaction::where('reference_id', $task->id)->where('kategori', 'GAJI_KARYAWAN')->sole();
    expect($wage->bank_account_id)->toBe($bank->id)
        ->and(AuditLog::where('action', 'finance.staff_paid')->where('model_id', $wage->id)->exists())->toBeTrue();

    expect(fn () => app(StaffPaymentService::class)->pay($task, $bank->id, $finance))
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
