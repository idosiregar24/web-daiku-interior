<?php

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\FinanceTransaction;
use App\Models\FundTransfer;
use App\Models\User;
use App\Services\FinanceTransactionService;
use App\Services\FundTransferService;
use Database\Seeders\RoleSeeder;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function fundTransferUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** @return array{0: BankAccount, 1: BankAccount} */
function transferAccounts(): array
{
    return [
        BankAccount::factory()->create(['label' => 'BCA Asal', 'opening_balance' => 10_000_000]),
        BankAccount::factory()->create(['label' => 'Mandiri Tujuan', 'opening_balance' => 1_000_000]),
    ];
}

function transferPayload(BankAccount $from, BankAccount $to, array $overrides = []): array
{
    return array_merge([
        'from_bank_account_id' => $from->id,
        'to_bank_account_id' => $to->id,
        'amount' => 2_500_000,
        'date' => now()->toDateString(),
        'description' => 'Top up rekening operasional',
    ], $overrides);
}

test('Finance records a transfer as two linked PINDAH_DANA legs plus an audit row', function () {
    $finance = fundTransferUser('FINANCE');
    [$from, $to] = transferAccounts();

    $this->actingAs($finance)
        ->post(route('finance.transfers.store'), transferPayload($from, $to))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $transfer = FundTransfer::sole();
    $legs = $transfer->transactions()->get()->keyBy(fn (FinanceTransaction $leg) => $leg->type->value);

    expect($legs)->toHaveCount(2)
        ->and($transfer->from_bank_account_id)->toBe($from->id)
        ->and($transfer->to_bank_account_id)->toBe($to->id)
        ->and((float) $transfer->amount)->toBe(2_500_000.0)
        ->and($transfer->created_by)->toBe($finance->id);

    $out = $legs['PENGELUARAN'];
    $in = $legs['PEMASUKAN'];

    expect($out->bank_account_id)->toBe($from->id)
        ->and($in->bank_account_id)->toBe($to->id)
        ->and($out->kategori)->toBe(FinanceCategory::PindahDana)
        ->and($in->kategori)->toBe(FinanceCategory::PindahDana)
        ->and($out->reference_id)->toBe($transfer->id)
        ->and($in->reference_id)->toBe($transfer->id)
        ->and((float) $out->amount)->toBe(2_500_000.0)
        ->and((float) $in->amount)->toBe(2_500_000.0)
        ->and($out->description)->toContain('ke Mandiri Tujuan')
        ->and($in->description)->toContain('dari BCA Asal')
        ->and($out->project_id)->toBeNull();

    $audit = AuditLog::where('action', 'finance.fund_transferred')->sole();
    expect($audit->model_type)->toBe('FundTransfer')
        ->and($audit->model_id)->toBe($transfer->id)
        ->and($audit->user_id)->toBe($finance->id)
        ->and($audit->new_values['out_transaction_id'])->toBe($out->id)
        ->and($audit->new_values['in_transaction_id'])->toBe($in->id)
        // Each leg also gets the standard per-transaction audit row.
        ->and(AuditLog::where('action', 'finance.transaction_created')->count())->toBe(2);
});

test('only Finance can move money between accounts', function (string $role) {
    [$from, $to] = transferAccounts();

    $this->actingAs(fundTransferUser($role))
        ->post(route('finance.transfers.store'), transferPayload($from, $to))
        ->assertForbidden();

    expect(FundTransfer::count())->toBe(0)
        ->and(FinanceTransaction::count())->toBe(0);
})->with(['CEO', 'PM', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'QA', 'LOGISTICS', 'FIELD_STAFF']);

test('SUPERADMIN passes the role gate like every other Finance route', function () {
    [$from, $to] = transferAccounts();

    $this->actingAs(fundTransferUser('SUPERADMIN'))
        ->post(route('finance.transfers.store'), transferPayload($from, $to))
        ->assertSessionHasNoErrors();

    expect(FundTransfer::count())->toBe(1);
});

test('an invalid transfer is rejected and writes nothing', function (Closure $overrides, string $field) {
    [$from, $to] = transferAccounts();

    $this->actingAs(fundTransferUser('FINANCE'))
        ->post(route('finance.transfers.store'), transferPayload($from, $to, $overrides($from, $to)))
        ->assertSessionHasErrors($field);

    expect(FundTransfer::count())->toBe(0)
        ->and(FinanceTransaction::count())->toBe(0);
})->with([
    'same account' => [fn (BankAccount $from) => ['to_bank_account_id' => $from->id], 'to_bank_account_id'],
    'inactive source' => [fn () => ['from_bank_account_id' => BankAccount::factory()->create(['is_active' => false])->id], 'from_bank_account_id'],
    'inactive destination' => [fn () => ['to_bank_account_id' => BankAccount::factory()->create(['is_active' => false])->id], 'to_bank_account_id'],
    'unknown account' => [fn () => ['to_bank_account_id' => 999_999], 'to_bank_account_id'],
    'zero amount' => [fn () => ['amount' => 0], 'amount'],
    'negative amount' => [fn () => ['amount' => -5_000], 'amount'],
    'future date' => [fn () => ['date' => now()->addDay()->toDateString()], 'date'],
    'missing description' => [fn () => ['description' => ''], 'description'],
    'description too long' => [fn () => ['description' => str_repeat('a', 151)], 'description'],
]);

test('the service re-checks the rules for callers that skip the request', function (Closure $overrides) {
    [$from, $to] = transferAccounts();

    expect(fn () => app(FundTransferService::class)->transfer(
        transferPayload($from, $to, $overrides($from)),
        fundTransferUser('FINANCE'),
    ))->toThrow(ValidationException::class);

    expect(FinanceTransaction::count())->toBe(0);
})->with([
    'same account' => [fn (BankAccount $from) => ['to_bank_account_id' => $from->id]],
    'inactive source' => [fn () => ['from_bank_account_id' => BankAccount::factory()->create(['is_active' => false])->id]],
    'zero amount' => [fn () => ['amount' => 0]],
    'future date' => [fn () => ['date' => now()->addDay()->toDateString()]],
]);

test('a transfer moves both account balances but not the company totals', function () {
    $finance = fundTransferUser('FINANCE');
    [$from, $to] = transferAccounts();

    app(FinanceTransactionService::class)->create([
        'bank_account_id' => $from->id,
        'type' => FinanceTransactionType::Income->value,
        'kategori' => FinanceCategory::Owner->value,
        'amount' => 500_000,
        'description' => 'Setoran modal',
        'date' => now()->toDateString(),
    ], $finance);

    app(FundTransferService::class)->transfer(transferPayload($from, $to), $finance);

    // Per account: both legs count.
    expect($from->fresh()->current_balance)->toBe(10_000_000.0 + 500_000 - 2_500_000)
        ->and($to->fresh()->current_balance)->toBe(1_000_000.0 + 2_500_000);

    // Company level: only the real income, the transfer nets out of nothing.
    $month = app(FinanceTransactionService::class)->monthlyCashFlow(1)->sole();
    expect($month['income'])->toBe(500_000.0)
        ->and($month['expense'])->toBe(0.0);

    $this->actingAs($finance)
        ->get(route('finance.transactions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('transactions.data', 3)
            ->where('summary.income', 500_000)
            ->where('summary.expense', 0)
            ->where('summary.excludesTransfers', true));

    // Scoped to one account, its transfer leg is real money out.
    $this->actingAs($finance)
        ->get(route('finance.transactions.index', ['bank_account_id' => $from->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('transactions.data', 2)
            ->where('summary.income', 500_000)
            ->where('summary.expense', 2_500_000)
            ->where('summary.excludesTransfers', false));
});

test('the CEO executive cash flow widget leaves transfers out too', function () {
    $finance = fundTransferUser('FINANCE');
    [$from, $to] = transferAccounts();
    app(FundTransferService::class)->transfer(transferPayload($from, $to), $finance);

    $this->actingAs(fundTransferUser('CEO'))
        ->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('cashFlow.5.income', 0)
            ->where('cashFlow.5.expense', 0));
});
