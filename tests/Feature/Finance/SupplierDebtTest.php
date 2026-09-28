<?php

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\FinanceTransaction;
use App\Models\Project;
use App\Models\SupplierDebt;
use App\Models\User;
use App\Services\SupplierDebtService;
use Database\Seeders\RoleSeeder;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function supplierDebtUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

test('CEO, PM and Finance can view the supplier debt index and detail', function (string $role) {
    $debt = SupplierDebt::factory()->create();
    $user = supplierDebtUser($role);

    $this->actingAs($user)->get(route('finance.supplierDebts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Finance/SupplierDebts/Index')->has('debts.data', 1));

    $this->actingAs($user)->get(route('finance.supplierDebts.show', $debt))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Finance/SupplierDebts/Show')->where('debt.id', $debt->id));
})->with(['CEO', 'PM', 'FINANCE']);

test('roles without finance access are forbidden from the supplier debt index', function (string $role) {
    $this->actingAs(supplierDebtUser($role))
        ->get(route('finance.supplierDebts.index'))
        ->assertForbidden();
})->with(['FIELD_STAFF', 'LOGISTICS', 'MARKETING', 'QA']);

test('CEO and PM cannot create debts or record payments', function (string $role) {
    $user = supplierDebtUser($role);
    $debt = SupplierDebt::factory()->create(['total_amount' => 1_000_000]);
    $bank = BankAccount::factory()->create();

    $this->actingAs($user)->get(route('finance.supplierDebts.create'))->assertForbidden();
    $this->actingAs($user)->post(route('finance.supplierDebts.store'), [
        'supplier_name' => 'Ideal',
        'total_amount' => 500000,
    ])->assertForbidden();
    $this->actingAs($user)->post(route('finance.supplierDebts.storePayment', $debt), [
        'amount' => 100000,
        'paid_date' => now()->toDateString(),
        'bank_account_id' => $bank->id,
    ])->assertForbidden();

    expect(SupplierDebt::count())->toBe(1)
        ->and($debt->fresh()->payments()->count())->toBe(0);
})->with(['CEO', 'PM']);

test('Finance creates a debt: only the liability, no finance transaction', function () {
    $finance = supplierDebtUser('FINANCE');
    $project = Project::factory()->create();

    $this->actingAs($finance)->get(route('finance.supplierDebts.create'))->assertOk();

    $this->actingAs($finance)->post(route('finance.supplierDebts.store'), [
        'supplier_name' => 'Kaca Jaya',
        'total_amount' => 2_500_000,
        'project_id' => $project->id,
        'due_date' => now()->addWeek()->toDateString(),
        'description' => 'Kaca tempered 8mm',
    ])->assertRedirect();

    $debt = SupplierDebt::firstOrFail();

    expect($debt->supplier_name)->toBe('Kaca Jaya')
        ->and((float) $debt->remaining)->toBe(2_500_000.0)
        ->and($debt->created_by)->toBe($finance->id)
        ->and($debt->status)->toBe(SupplierDebt::STATUS_BERJALAN)
        ->and(FinanceTransaction::count())->toBe(0)
        ->and(AuditLog::where('action', 'finance.supplier_debt_created')->where('model_id', $debt->id)->exists())->toBeTrue();
});

test('store validates required fields with Indonesian messages', function () {
    $this->actingAs(supplierDebtUser('FINANCE'))
        ->post(route('finance.supplierDebts.store'), ['total_amount' => 0])
        ->assertSessionHasErrors([
            'supplier_name' => 'Nama supplier wajib diisi.',
            'total_amount' => 'Total hutang harus lebih dari 0.',
        ]);
});

test('a payment writes exactly one HUTANG_IDEAL expense with the bank account, plus audit rows', function () {
    $finance = supplierDebtUser('FINANCE');
    $project = Project::factory()->create();
    $bank = BankAccount::factory()->create();
    $debt = app(SupplierDebtService::class)->create([
        'supplier_name' => 'Ideal',
        'total_amount' => 1_000_000,
        'project_id' => $project->id,
    ], $finance);

    $this->actingAs($finance)->post(route('finance.supplierDebts.storePayment', $debt), [
        'amount' => 400_000,
        'paid_date' => now()->toDateString(),
        'bank_account_id' => $bank->id,
        'note' => 'Cicilan pertama',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $debt->refresh();
    $transaction = FinanceTransaction::sole();

    expect((float) $debt->paid_amount)->toBe(400_000.0)
        ->and((float) $debt->remaining)->toBe(600_000.0)
        ->and($debt->payments()->count())->toBe(1)
        ->and($transaction->type)->toBe(FinanceTransactionType::Expense)
        ->and($transaction->kategori)->toBe(FinanceCategory::HutangIdeal)
        ->and((float) $transaction->amount)->toBe(400_000.0)
        ->and($transaction->bank_account_id)->toBe($bank->id)
        ->and($transaction->project_id)->toBe($project->id)
        ->and((int) $transaction->reference_id)->toBe($debt->id)
        ->and(AuditLog::where('action', 'finance.supplier_debt_paid')->where('model_id', $debt->id)->count())->toBe(1)
        ->and(AuditLog::where('action', 'finance.transaction_created')->count())->toBe(1);
});

test('paying the full remaining amount marks the debt LUNAS', function () {
    $finance = supplierDebtUser('FINANCE');
    $bank = BankAccount::factory()->create();
    $service = app(SupplierDebtService::class);
    $debt = $service->create(['supplier_name' => 'Ideal', 'total_amount' => 750_000, 'due_date' => now()->subDay()->toDateString()], $finance);

    expect($debt->status)->toBe(SupplierDebt::STATUS_JATUH_TEMPO);

    $service->recordPayment($debt, ['amount' => 250_000, 'paid_date' => now()->toDateString(), 'bank_account_id' => $bank->id], $finance);
    $service->recordPayment($debt, ['amount' => 500_000, 'paid_date' => now()->toDateString(), 'bank_account_id' => $bank->id], $finance);

    $debt->refresh();

    expect((float) $debt->remaining)->toBe(0.0)
        ->and($debt->status)->toBe(SupplierDebt::STATUS_LUNAS)
        ->and(FinanceTransaction::count())->toBe(2)
        ->and(SupplierDebt::outstanding()->count())->toBe(0);
});

test('over-payment is rejected and nothing is written', function () {
    $finance = supplierDebtUser('FINANCE');
    $bank = BankAccount::factory()->create();
    $debt = app(SupplierDebtService::class)->create(['supplier_name' => 'Ideal', 'total_amount' => 300_000], $finance);

    $this->actingAs($finance)->post(route('finance.supplierDebts.storePayment', $debt), [
        'amount' => 300_001,
        'paid_date' => now()->toDateString(),
        'bank_account_id' => $bank->id,
    ])->assertSessionHasErrors('amount');

    expect((float) $debt->fresh()->paid_amount)->toBe(0.0)
        ->and($debt->payments()->count())->toBe(0)
        ->and(FinanceTransaction::count())->toBe(0);
});

test('service rejects a non-positive amount', function () {
    $finance = supplierDebtUser('FINANCE');
    $bank = BankAccount::factory()->create();
    $debt = app(SupplierDebtService::class)->create(['supplier_name' => 'Ideal', 'total_amount' => 300_000], $finance);

    app(SupplierDebtService::class)->recordPayment($debt, ['amount' => 0, 'paid_date' => now()->toDateString(), 'bank_account_id' => $bank->id], $finance);
})->throws(ValidationException::class);

test('payment requires an active bank account', function () {
    $finance = supplierDebtUser('FINANCE');
    $inactive = BankAccount::factory()->create(['is_active' => false]);
    $debt = SupplierDebt::factory()->create(['total_amount' => 500_000]);

    $this->actingAs($finance)->post(route('finance.supplierDebts.storePayment', $debt), [
        'amount' => 100_000,
        'paid_date' => now()->toDateString(),
    ])->assertSessionHasErrors('bank_account_id');

    $this->actingAs($finance)->post(route('finance.supplierDebts.storePayment', $debt), [
        'amount' => 100_000,
        'paid_date' => now()->toDateString(),
        'bank_account_id' => $inactive->id,
    ])->assertSessionHasErrors('bank_account_id');
});

test('overdue scope and derived status', function () {
    $overdue = SupplierDebt::factory()->overdue()->create(['total_amount' => 100_000]);
    $running = SupplierDebt::factory()->create(['total_amount' => 100_000]);
    $noDueDate = SupplierDebt::factory()->create(['total_amount' => 100_000, 'due_date' => null]);
    $paidOffPastDue = SupplierDebt::factory()->overdue()->paidOff()->create();

    expect(SupplierDebt::overdue()->pluck('id')->all())->toBe([$overdue->id])
        ->and(SupplierDebt::outstanding()->count())->toBe(3)
        ->and($overdue->fresh()->status)->toBe('JATUH_TEMPO')
        ->and($running->fresh()->status)->toBe('BERJALAN')
        ->and($noDueDate->fresh()->status)->toBe('BERJALAN')
        ->and($paidOffPastDue->fresh()->status)->toBe('LUNAS');
});

test('index filters by status and supplier name', function () {
    $finance = supplierDebtUser('FINANCE');
    SupplierDebt::factory()->overdue()->create(['supplier_name' => 'Kaca Jaya']);
    SupplierDebt::factory()->create(['supplier_name' => 'Ideal']);
    SupplierDebt::factory()->paidOff()->create(['supplier_name' => 'Ideal Lunas']);

    $this->actingAs($finance)->get(route('finance.supplierDebts.index', ['status' => 'JATUH_TEMPO']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('debts.data', 1)
            ->where('debts.data.0.supplier_name', 'Kaca Jaya')
            ->where('debts.data.0.status', 'JATUH_TEMPO')
            ->where('summary.overdueCount', 1));

    $this->actingAs($finance)->get(route('finance.supplierDebts.index', ['search' => 'ideal']))
        ->assertInertia(fn (Assert $page) => $page->has('debts.data', 2));

    $this->actingAs($finance)->get(route('finance.supplierDebts.index', ['status' => 'LUNAS']))
        ->assertInertia(fn (Assert $page) => $page->has('debts.data', 1)->where('debts.data.0.supplier_name', 'Ideal Lunas'));
});

test('outstandingForProject returns only unpaid debts of that project', function () {
    $project = Project::factory()->create();
    SupplierDebt::factory()->create(['project_id' => $project->id, 'total_amount' => 200_000]);
    SupplierDebt::factory()->paidOff()->create(['project_id' => $project->id]);
    SupplierDebt::factory()->create(['total_amount' => 999_000]);

    $service = app(SupplierDebtService::class);

    expect($service->outstandingForProject($project))->toHaveCount(1)
        ->and($service->outstandingTotalForProject($project))->toBe(200_000.0);
});
