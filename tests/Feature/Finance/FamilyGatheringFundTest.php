<?php

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\FamilyGatheringFund;
use App\Models\FinanceTransaction;
use App\Models\Penalty;
use App\Models\User;
use App\Services\FamilyGatheringFundService;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

/** INCOME row tied to a penalty, paid or not (Sprint 9 decision #10). */
function fundPenaltyIncome(bool $paid, float $amount = 50000): FamilyGatheringFund
{
    $penalty = Penalty::factory()->state($paid ? ['is_deducted' => true, 'collected_at' => now()] : [])->create(['amount' => $amount]);

    return FamilyGatheringFund::factory()->forPenalty($penalty)->create();
}

function fundExpensePayload(BankAccount $bank, array $overrides = []): array
{
    return [
        'amount' => 200000,
        'description' => 'Acara gathering Q3',
        'bank_account_id' => $bank->id,
        'date' => now()->toDateString(),
        ...$overrides,
    ];
}

test('CEO and FINANCE can view the family fund page', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('family-fund.index'))->assertOk();
})->with(['CEO', 'FINANCE']);

test('roles without access are forbidden from the family fund page', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('family-fund.index'))->assertForbidden();
})->with(['MARKETING', 'PM', 'FIELD_STAFF']);

test('only paid penalties (plus income not tied to a penalty) are spendable', function () {
    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');

    fundPenaltyIncome(paid: true);
    fundPenaltyIncome(paid: true);
    fundPenaltyIncome(paid: false);
    FamilyGatheringFund::factory()->create(['type' => 'INCOME', 'amount' => 10000]);
    FamilyGatheringFund::factory()->create(['type' => 'EXPENSE', 'amount' => 30000]);

    $this->actingAs($finance)->get(route('family-fund.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.penaltyTotal', 150000)
            ->where('summary.collected', 100000)
            ->where('summary.outstanding', 50000)
            ->where('summary.otherIncome', 10000)
            ->where('summary.totalExpense', 30000)
            ->where('summary.spendable', 80000)
            ->where('canRecordExpense', true)
            ->has('bankAccounts'));
});

test('CEO reads the fund page without the expense action', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');

    $this->actingAs($ceo)->get(route('family-fund.index'))
        ->assertInertia(fn (Assert $page) => $page->where('canRecordExpense', false)->where('bankAccounts', []));
});

test('finance records a fund expense paid out of a bank account', function () {
    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');
    $bank = BankAccount::factory()->create();
    foreach (range(1, 4) as $i) {
        fundPenaltyIncome(paid: true);
    }

    $this->actingAs($finance)->post(route('family-fund.recordExpense'), fundExpensePayload($bank))
        ->assertRedirect()->assertSessionHasNoErrors();

    $entry = FamilyGatheringFund::where('type', 'EXPENSE')->sole();
    $transaction = FinanceTransaction::sole();

    expect((float) $entry->amount)->toBe(200000.0)
        ->and($entry->recorded_by)->toBe($finance->id)
        ->and($entry->finance_transaction_id)->toBe($transaction->id)
        ->and($transaction->type)->toBe(FinanceTransactionType::Expense)
        ->and($transaction->kategori)->toBe(FinanceCategory::Lainnya)
        ->and($transaction->bank_account_id)->toBe($bank->id)
        ->and((float) $transaction->amount)->toBe(200000.0)
        ->and(AuditLog::where('action', 'finance.family_fund_expense')->count())->toBe(1)
        ->and(app(FamilyGatheringFundService::class)->spendableBalance())->toBe(0.0);
});

test('a fund expense cannot spend penalties that are not paid yet', function () {
    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');
    fundPenaltyIncome(paid: true);
    fundPenaltyIncome(paid: false);

    $this->actingAs($finance)->post(route('family-fund.recordExpense'), fundExpensePayload(BankAccount::factory()->create(), [
        'amount' => 50001,
    ]))->assertSessionHasErrors('amount');

    expect(FamilyGatheringFund::where('type', 'EXPENSE')->exists())->toBeFalse()
        ->and(FinanceTransaction::count())->toBe(0);
});

test('a fund expense requires an active bank account and a date not in the future', function (array $overrides, string $field) {
    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');
    fundPenaltyIncome(paid: true, amount: 500000);
    $payload = fundExpensePayload(BankAccount::factory()->create(), $overrides);

    if (($overrides['bank_account_id'] ?? null) === 'inactive') {
        $payload['bank_account_id'] = BankAccount::factory()->create(['is_active' => false])->id;
    }

    $this->actingAs($finance)->post(route('family-fund.recordExpense'), $payload)->assertSessionHasErrors($field);

    expect(FamilyGatheringFund::where('type', 'EXPENSE')->exists())->toBeFalse()
        ->and(FinanceTransaction::count())->toBe(0);
})->with([
    'missing account' => [['bank_account_id' => null], 'bank_account_id'],
    'inactive account' => [['bank_account_id' => 'inactive'], 'bank_account_id'],
    'future date' => [['date' => '2099-01-01'], 'date'],
]);

test('roles other than FINANCE cannot record a fund expense', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);
    fundPenaltyIncome(paid: true, amount: 500000);

    $this->actingAs($user)->post(route('family-fund.recordExpense'), fundExpensePayload(BankAccount::factory()->create()))
        ->assertForbidden();
})->with(['CEO', 'PM', 'FIELD_STAFF']);
