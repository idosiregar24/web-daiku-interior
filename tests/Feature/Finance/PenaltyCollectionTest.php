<?php

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\FamilyGatheringFund;
use App\Models\FinanceTransaction;
use App\Models\Penalty;
use App\Models\User;
use App\Services\PenaltyCollectionService;
use Database\Seeders\RoleSeeder;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function penaltyCollectionUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** A penalty with the INCOME fund row PenaltyService writes on issue. */
function issuedPenalty(User $staff, array $attributes = []): Penalty
{
    $penalty = Penalty::factory()->create(['staff_id' => $staff->id, ...$attributes]);
    FamilyGatheringFund::factory()->forPenalty($penalty)->create();

    return $penalty;
}

function penaltyPaymentPayload(User $staff, array $penaltyIds, BankAccount $bank, array $overrides = []): array
{
    return [
        'staff_id' => $staff->id,
        'penalty_ids' => $penaltyIds,
        'bank_account_id' => $bank->id,
        'date' => now()->toDateString(),
        'note' => 'Tunai di kantor',
        ...$overrides,
    ];
}

test('finance records a penalty payment — transaction, flags and audit written together', function () {
    $finance = penaltyCollectionUser('FINANCE');
    $staff = penaltyCollectionUser('FIELD_STAFF');
    $staff->update(['name' => 'Slamet Wijaya']);
    $bank = BankAccount::factory()->create();
    $a = issuedPenalty($staff);
    $b = issuedPenalty($staff, ['date_occurred' => now()->subDay()->toDateString()]);
    $untouched = issuedPenalty($staff, ['date_occurred' => now()->subDays(2)->toDateString()]);

    $this->actingAs($finance)
        ->post(route('penalties.recordPayment'), penaltyPaymentPayload($staff, [$a->id, $b->id], $bank))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $transaction = FinanceTransaction::sole();
    expect($transaction->type)->toBe(FinanceTransactionType::Income)
        ->and($transaction->kategori)->toBe(FinanceCategory::PenaltyCollect)
        ->and((float) $transaction->amount)->toBe(100000.0)
        ->and($transaction->bank_account_id)->toBe($bank->id)
        ->and($transaction->reference_id)->toBeNull()
        ->and($transaction->description)->toBe('Pembayaran penalti Slamet Wijaya (2 penalti) — Tunai di kantor');

    foreach ([$a, $b] as $penalty) {
        $penalty->refresh();
        expect($penalty->is_deducted)->toBeTrue()
            ->and($penalty->collected_at)->not->toBeNull()
            ->and($penalty->collected_by)->toBe($finance->id)
            ->and($penalty->finance_transaction_id)->toBe($transaction->id);
    }

    expect($untouched->fresh()->is_deducted)->toBeFalse();

    $log = AuditLog::where('action', 'finance.penalty_collected')->sole();
    expect($log->user_id)->toBe($finance->id)
        ->and($log->model_id)->toBe($transaction->id)
        ->and($log->new_values['penalty_ids'])->toBe([$a->id, $b->id])
        ->and((float) $log->new_values['amount'])->toBe(100000.0);
});

test('only finance can record a penalty payment', function (string $role) {
    $staff = penaltyCollectionUser('FIELD_STAFF');
    $penalty = issuedPenalty($staff);
    $actor = $role === 'SELF' ? $staff : penaltyCollectionUser($role);

    $this->actingAs($actor)
        ->post(route('penalties.recordPayment'), penaltyPaymentPayload($staff, [$penalty->id], BankAccount::factory()->create()))
        ->assertForbidden();

    expect($penalty->fresh()->is_deducted)->toBeFalse()
        ->and(FinanceTransaction::count())->toBe(0);
})->with(['CEO', 'PM', 'FIELD_STAFF', 'SELF', 'MARKETING', 'QA', 'LOGISTICS']);

test('penalties of another staff member are rejected and nothing is written', function () {
    $staff = penaltyCollectionUser('FIELD_STAFF');
    $other = penaltyCollectionUser('FIELD_STAFF');
    $own = issuedPenalty($staff);
    $foreign = issuedPenalty($other);

    $this->actingAs(penaltyCollectionUser('FINANCE'))
        ->post(route('penalties.recordPayment'), penaltyPaymentPayload($staff, [$own->id, $foreign->id], BankAccount::factory()->create()))
        ->assertSessionHasErrors('penalty_ids');

    expect(FinanceTransaction::count())->toBe(0)
        ->and(Penalty::where('is_deducted', true)->count())->toBe(0)
        ->and(AuditLog::count())->toBe(0);
});

test('a penalty cannot be paid twice', function () {
    $finance = penaltyCollectionUser('FINANCE');
    $staff = penaltyCollectionUser('FIELD_STAFF');
    $bank = BankAccount::factory()->create();
    $penalty = issuedPenalty($staff);
    $payload = penaltyPaymentPayload($staff, [$penalty->id], $bank);

    $this->actingAs($finance)->post(route('penalties.recordPayment'), $payload)->assertSessionHasNoErrors();
    // The double-click.
    $this->actingAs($finance)->post(route('penalties.recordPayment'), $payload)->assertSessionHasErrors('penalty_ids');

    expect(FinanceTransaction::count())->toBe(1)
        ->and(AuditLog::where('action', 'finance.penalty_collected')->count())->toBe(1);
});

test('validation failures write nothing', function (array $overrides, string $field) {
    $staff = penaltyCollectionUser('FIELD_STAFF');
    $penalty = issuedPenalty($staff);
    $inactive = BankAccount::factory()->create(['is_active' => false]);
    $payload = penaltyPaymentPayload($staff, [$penalty->id], BankAccount::factory()->create(), $overrides);

    if (($overrides['bank_account_id'] ?? null) === 'inactive') {
        $payload['bank_account_id'] = $inactive->id;
    }

    $this->actingAs(penaltyCollectionUser('FINANCE'))
        ->post(route('penalties.recordPayment'), $payload)
        ->assertSessionHasErrors($field);

    expect(FinanceTransaction::count())->toBe(0)
        ->and($penalty->fresh()->is_deducted)->toBeFalse();
})->with([
    'no penalties' => [['penalty_ids' => []], 'penalty_ids'],
    'unknown penalty' => [['penalty_ids' => [999999]], 'penalty_ids'],
    'missing account' => [['bank_account_id' => null], 'bank_account_id'],
    'inactive account' => [['bank_account_id' => 'inactive'], 'bank_account_id'],
    'future date' => [['date' => '2099-01-01'], 'date'],
]);

test('the service re-checks rules for callers that skip the request', function () {
    $staff = penaltyCollectionUser('FIELD_STAFF');
    $penalty = issuedPenalty($staff);

    expect(fn () => app(PenaltyCollectionService::class)->recordPayment($staff, [
        'penalty_ids' => [$penalty->id],
        'bank_account_id' => null,
        'date' => now()->toDateString(),
    ], penaltyCollectionUser('FINANCE')))->toThrow(ValidationException::class);

    expect(FinanceTransaction::count())->toBe(0);
});

test('paying a penalty that never reached the fund writes its income row', function () {
    $staff = penaltyCollectionUser('FIELD_STAFF');
    $penalty = Penalty::factory()->create(['staff_id' => $staff->id]);

    app(PenaltyCollectionService::class)->recordPayment($staff, [
        'penalty_ids' => [$penalty->id],
        'bank_account_id' => BankAccount::factory()->create()->id,
        'date' => now()->toDateString(),
    ], penaltyCollectionUser('FINANCE'));

    expect(FamilyGatheringFund::where('type', 'INCOME')->where('source_penalty_id', $penalty->id)->count())->toBe(1);
});

test('PENALTY_COLLECT cannot be entered through the manual transaction form', function () {
    $this->actingAs(penaltyCollectionUser('FINANCE'))
        ->post(route('finance.transactions.store'), [
            'bank_account_id' => BankAccount::factory()->create()->id,
            'type' => FinanceTransactionType::Income->value,
            'kategori' => FinanceCategory::PenaltyCollect->value,
            'amount' => 50000,
            'description' => 'Penalti manual',
            'date' => now()->toDateString(),
        ])
        ->assertSessionHasErrors('kategori');

    expect(FinanceTransaction::count())->toBe(0);
});

test('the penalty page shows paid/unpaid totals, filters by status and gives finance the payment data', function () {
    $staff = penaltyCollectionUser('FIELD_STAFF');
    $paid = issuedPenalty($staff);
    issuedPenalty($staff);
    issuedPenalty($staff);
    app(PenaltyCollectionService::class)->recordPayment($staff, [
        'penalty_ids' => [$paid->id],
        'bank_account_id' => BankAccount::factory()->create()->id,
        'date' => now()->toDateString(),
    ], penaltyCollectionUser('FINANCE'));

    $this->actingAs(penaltyCollectionUser('FINANCE'))->get(route('penalties.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('grandTotal', 150000)
            ->where('paidTotal', 50000)
            ->where('outstandingTotal', 100000)
            ->where('perStaff.0.unpaidCount', 2)
            ->where('perStaff.0.outstanding', 100000)
            ->where('canRecordPayment', true)
            ->has('unpaidPenalties', 2)
            ->has('bankAccounts', 1));

    $this->actingAs(penaltyCollectionUser('CEO'))->get(route('penalties.index', ['status' => 'LUNAS']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('penalties.data', 1)
            ->where('penalties.data.0.id', $paid->id)
            ->where('canRecordPayment', false)
            ->where('unpaidPenalties', [])
            ->where('bankAccounts', []));

    $this->actingAs(penaltyCollectionUser('PM'))->get(route('penalties.index', ['status' => 'BELUM_DIBAYAR']))
        ->assertInertia(fn (Assert $page) => $page->has('penalties.data', 2));
});

test('field staff see their own penalties with status but no account data', function () {
    $staff = penaltyCollectionUser('FIELD_STAFF');
    $penalty = issuedPenalty($staff);
    issuedPenalty(penaltyCollectionUser('FIELD_STAFF'));
    app(PenaltyCollectionService::class)->recordPayment($staff, [
        'penalty_ids' => [$penalty->id],
        'bank_account_id' => BankAccount::factory()->create()->id,
        'date' => now()->toDateString(),
    ], penaltyCollectionUser('FINANCE'));

    $this->actingAs($staff)->get(route('penalties.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('penalties.data', 1)
            ->where('penalties.data.0.is_deducted', true)
            ->missing('penalties.data.0.finance_transaction')
            ->where('paidTotal', 50000)
            ->where('outstandingTotal', 0)
            ->where('canRecordPayment', false));
});
