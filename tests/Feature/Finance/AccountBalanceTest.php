<?php

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Models\BankAccount;
use App\Models\FinanceTransaction;
use App\Models\User;
use App\Services\FinanceTransactionService;
use App\Services\FundTransferService;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function accountBalanceUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function bookTransaction(BankAccount $account, FinanceTransactionType $type, float $amount, string $date, FinanceCategory $kategori = FinanceCategory::Lainnya): FinanceTransaction
{
    return FinanceTransaction::factory()->create([
        'bank_account_id' => $account->id,
        'type' => $type->value,
        'kategori' => $kategori->value,
        'amount' => $amount,
        'date' => $date,
    ]);
}

test('the current balance is derived from the opening balance and the account transactions', function () {
    $account = BankAccount::factory()->create(['opening_balance' => 1_000_000]);
    $other = BankAccount::factory()->create(['opening_balance' => 0]);

    bookTransaction($account, FinanceTransactionType::Income, 750_000, now()->toDateString());
    bookTransaction($account, FinanceTransactionType::Expense, 250_000.50, now()->subMonth()->toDateString());
    bookTransaction($other, FinanceTransactionType::Expense, 999_999, now()->toDateString());

    // Fallback accessor (no scope) and the withBalance() scope agree.
    expect($account->fresh()->current_balance)->toBe(1_499_999.5);

    $loaded = BankAccount::query()->withBalance()->findOrFail($account->id);
    expect($loaded->total_income)->toBe('750000.00')
        ->and($loaded->total_expense)->toBe('250000.50')
        ->and($loaded->current_balance)->toBe(1_499_999.5);

    // No transactions yet: just the saldo awal.
    $fresh = BankAccount::factory()->create(['opening_balance' => 42_000]);
    expect(BankAccount::query()->withBalance()->findOrFail($fresh->id)->current_balance)->toBe(42_000.0);
});

test('the Finance Dashboard summarises every active account and the company total for a month', function () {
    $bca = BankAccount::factory()->create(['label' => 'BCA', 'opening_balance' => 10_000_000]);
    $mandiri = BankAccount::factory()->create(['label' => 'Mandiri', 'opening_balance' => 2_000_000]);
    $emptyInactive = BankAccount::factory()->create(['label' => 'Lama Kosong', 'opening_balance' => 0, 'is_active' => false]);
    $inactiveWithMoney = BankAccount::factory()->create(['label' => 'Lama Bersaldo', 'opening_balance' => 300_000, 'is_active' => false]);

    bookTransaction($bca, FinanceTransactionType::Income, 5_000_000, '2026-08-10', FinanceCategory::Termin);
    bookTransaction($bca, FinanceTransactionType::Expense, 1_000_000, '2026-08-31', FinanceCategory::BeliBahan);
    bookTransaction($mandiri, FinanceTransactionType::Expense, 400_000, '2026-09-01', FinanceCategory::Operasional);
    app(FundTransferService::class)->transfer([
        'from_bank_account_id' => $bca->id,
        'to_bank_account_id' => $mandiri->id,
        'amount' => 3_000_000,
        'date' => '2026-08-20',
        'description' => 'Top up',
    ], accountBalanceUser('FINANCE'));

    $this->actingAs(accountBalanceUser('FINANCE'))
        ->get(route('finance.dashboard', ['month' => '2026-08']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Finance/Dashboard')
            ->has('cashFlow', 6)
            ->where('accountSummary.month', '2026-08')
            // Active first, inactive-with-balance kept, empty inactive dropped.
            ->has('accountSummary.accounts', 3)
            ->where('accountSummary.accounts.0.label', 'BCA')
            ->where('accountSummary.accounts.0.opening_balance', 10_000_000)
            ->where('accountSummary.accounts.0.total_income', 5_000_000)
            ->where('accountSummary.accounts.0.total_expense', 4_000_000)
            ->where('accountSummary.accounts.0.current_balance', 11_000_000)
            ->where('accountSummary.accounts.0.month_income', 5_000_000)
            ->where('accountSummary.accounts.0.month_expense', 4_000_000)
            ->where('accountSummary.accounts.1.label', 'Mandiri')
            ->where('accountSummary.accounts.1.total_income', 3_000_000)
            ->where('accountSummary.accounts.1.current_balance', 4_600_000)
            ->where('accountSummary.accounts.1.month_income', 3_000_000)
            // 1 September is outside August.
            ->where('accountSummary.accounts.1.month_expense', 0)
            ->where('accountSummary.accounts.2.label', 'Lama Bersaldo')
            ->where('accountSummary.accounts.2.is_active', false)
            // Keseluruhan: balances summed, masuk/keluar without the transfer.
            ->where('accountSummary.total.opening_balance', 12_300_000)
            ->where('accountSummary.total.current_balance', 15_900_000)
            ->where('accountSummary.total.total_income', 5_000_000)
            ->where('accountSummary.total.total_expense', 1_400_000)
            ->where('accountSummary.total.month_income', 5_000_000)
            ->where('accountSummary.total.month_expense', 1_000_000));

    expect(collect(app(FinanceTransactionService::class)->accountSummary(now()->startOfMonth())['accounts'])->pluck('id'))
        ->not->toContain($emptyInactive->id)
        ->toContain($inactiveWithMoney->id);
});

test('the dashboard defaults to the current month and rejects a malformed one', function () {
    $finance = accountBalanceUser('FINANCE');

    $this->actingAs($finance)
        ->get(route('finance.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('accountSummary.month', now()->format('Y-m')));

    $this->actingAs($finance)
        ->from(route('finance.transactions.index'))
        ->get(route('finance.dashboard', ['month' => '2026-13']))
        ->assertSessionHasErrors('month');
});

test('the dashboard with its per-account summary stays readable by CEO and PM only besides Finance', function (string $role, int $status) {
    $this->actingAs(accountBalanceUser($role))->get(route('finance.dashboard'))->assertStatus($status);
})->with([
    ['CEO', 200],
    ['PM', 200],
    ['FINANCE', 200],
    ['MARKETING', 403],
    ['QA', 403],
    ['FIELD_STAFF', 403],
]);

test('month labels never overflow on the 31st', function () {
    $this->travelTo('2026-10-31 10:00:00');

    $labels = app(FinanceTransactionService::class)->monthlyCashFlow(6)->pluck('month');

    expect($labels->all())->toBe(['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'])
        ->and(app(FinanceTransactionService::class)->monthlyCashFlow(6)->pluck('label')->unique())->toHaveCount(6);
});
