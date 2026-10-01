<?php

use App\Enums\FinanceTransactionType;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\FinanceTransaction;
use App\Models\LeadCategory;
use App\Models\LeadSource;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->superadmin = User::factory()->create();
    $this->superadmin->assignRole('SUPERADMIN');
});

test('non-superadmin roles are forbidden from master data', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('master-data.index'))->assertForbidden();
})->with(['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'QA', 'FINANCE', 'LOGISTICS', 'FIELD_STAFF']);

test('superadmin can view master data index', function () {
    $this->actingAs($this->superadmin)->get(route('master-data.index'))->assertOk();
});

test('superadmin can create, update and delete a branch', function () {
    $this->actingAs($this->superadmin)->post(route('master-data.branches.store'), [
        'name' => 'Cabang Jakarta',
        'code' => 'JKT01',
        'address' => 'Jl. Sudirman',
    ])->assertRedirect();

    $branch = Branch::firstWhere('code', 'JKT01');
    expect($branch)->not->toBeNull();

    $this->actingAs($this->superadmin)->put(route('master-data.branches.update', $branch), [
        'name' => 'Cabang Jakarta Pusat',
        'code' => 'JKT01',
        'address' => 'Jl. Sudirman No. 1',
    ])->assertRedirect();

    expect($branch->fresh()->name)->toBe('Cabang Jakarta Pusat');

    $this->actingAs($this->superadmin)->delete(route('master-data.branches.destroy', $branch))->assertRedirect();
    expect(Branch::find($branch->id))->toBeNull();
});

test('branch code must be unique', function () {
    Branch::factory()->create(['code' => 'JKT01']);

    $this->actingAs($this->superadmin)->post(route('master-data.branches.store'), [
        'name' => 'Cabang Lain',
        'code' => 'JKT01',
    ])->assertSessionHasErrors('code');
});

test('superadmin can create a lead source', function () {
    $this->actingAs($this->superadmin)->post(route('master-data.lead-sources.store'), [
        'name' => 'YouTube',
    ])->assertRedirect();

    expect(LeadSource::where('name', 'YouTube')->exists())->toBeTrue();
});

test('superadmin can create a lead category', function () {
    $this->actingAs($this->superadmin)->post(route('master-data.lead-categories.store'), [
        'name' => 'HORECA',
    ])->assertRedirect();

    expect(LeadCategory::where('name', 'HORECA')->exists())->toBeTrue();
});

test('superadmin can create a bank account', function () {
    $this->actingAs($this->superadmin)->post(route('master-data.bank-accounts.store'), [
        'bank_name' => 'BCA',
        'account_no' => '1234567890',
        'label' => 'BCA 7890',
        'opening_balance' => 1_000_000,
        'is_active' => true,
    ])->assertRedirect();

    expect((float) BankAccount::where('label', 'BCA 7890')->sole()->opening_balance)->toBe(1_000_000.0)
        ->and(AuditLog::where('action', 'finance.bank_account_created')->exists())->toBeTrue();
});

test('bank account label must be unique', function () {
    BankAccount::factory()->create(['label' => 'BCA 5835']);

    $this->actingAs($this->superadmin)->post(route('master-data.bank-accounts.store'), [
        'bank_name' => 'BCA',
        'account_no' => '5835',
        'label' => 'BCA 5835',
        'opening_balance' => 0,
    ])->assertSessionHasErrors('label');
});

test('the bank account tab shows the derived current balance next to the saldo awal', function () {
    $account = BankAccount::factory()->create(['opening_balance' => 1_000_000]);
    FinanceTransaction::factory()->create([
        'bank_account_id' => $account->id,
        'type' => FinanceTransactionType::Expense->value,
        'amount' => 250_000,
    ]);

    $this->actingAs($this->superadmin)
        ->get(route('master-data.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('bankAccounts.0.opening_balance', '1000000.00')
            ->where('bankAccounts.0.current_balance', 750_000));
});

test('changing the saldo awal is audited, other edits are not', function () {
    $account = BankAccount::factory()->create(['label' => 'BCA 1', 'opening_balance' => 1_000_000]);
    $payload = ['bank_name' => 'BCA', 'account_no' => '111', 'label' => 'BCA 1', 'opening_balance' => 1_000_000, 'is_active' => true];

    $this->actingAs($this->superadmin)->put(route('master-data.bank-accounts.update', $account), $payload)->assertSessionHasNoErrors();
    expect(AuditLog::where('action', 'finance.opening_balance_changed')->exists())->toBeFalse();

    $this->actingAs($this->superadmin)->put(route('master-data.bank-accounts.update', $account), [...$payload, 'opening_balance' => 1_500_000]);

    $audit = AuditLog::where('action', 'finance.opening_balance_changed')->sole();
    expect($audit->old_values['opening_balance'])->toBe('1000000.00')
        ->and($audit->new_values['opening_balance'])->toBe('1500000.00')
        ->and($audit->user_id)->toBe($this->superadmin->id);
});

test('a negative saldo awal is rejected', function () {
    $this->actingAs($this->superadmin)->post(route('master-data.bank-accounts.store'), [
        'bank_name' => 'BCA', 'account_no' => '1', 'label' => 'BCA X', 'opening_balance' => -1,
    ])->assertSessionHasErrors(['opening_balance' => 'Saldo awal tidak boleh negatif.']);
});

test('a bank account with transactions cannot be deleted, an unused one can', function () {
    $used = BankAccount::factory()->create();
    FinanceTransaction::factory()->create(['bank_account_id' => $used->id]);
    $unused = BankAccount::factory()->create();

    $this->actingAs($this->superadmin)->delete(route('master-data.bank-accounts.destroy', $used))
        ->assertSessionHas('error');
    $this->actingAs($this->superadmin)->delete(route('master-data.bank-accounts.destroy', $unused))
        ->assertSessionHas('success');

    expect(BankAccount::find($used->id))->not->toBeNull()
        ->and(BankAccount::find($unused->id))->toBeNull();
});

test('the Sprint 9 migration renames balance to opening_balance and back, keeping the values', function () {
    $migration = require database_path('migrations/2026_09_30_083941_rename_balance_to_opening_balance_on_bank_accounts_table.php');
    $account = BankAccount::factory()->create(['opening_balance' => 123_000]);

    $migration->down();
    expect(Schema::hasColumn('bank_accounts', 'balance'))->toBeTrue()
        ->and(Schema::hasColumn('bank_accounts', 'opening_balance'))->toBeFalse()
        ->and((float) DB::table('bank_accounts')->where('id', $account->id)->value('balance'))->toBe(123_000.0);

    $migration->up();
    expect(Schema::hasColumn('bank_accounts', 'opening_balance'))->toBeTrue()
        ->and((float) $account->fresh()->opening_balance)->toBe(123_000.0);
});
