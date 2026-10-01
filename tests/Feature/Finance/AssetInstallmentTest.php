<?php

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Models\Asset;
use App\Models\AssetInstallmentPayment;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\FinanceTransaction;
use App\Models\User;
use App\Services\AssetInstallmentService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function assetInstallmentUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function installmentAsset(float $total = 12_000_000, ?float $perPayment = 1_000_000, ?int $dueDay = 10, array $attributes = []): Asset
{
    return Asset::factory()->withInstallmentPlan($total, $perPayment, $dueDay)->create(['name' => 'Mobil Pickup L300', ...$attributes]);
}

function payInstallment(Asset $asset, float $amount, ?User $finance = null, array $overrides = []): AssetInstallmentPayment
{
    return app(AssetInstallmentService::class)->recordPayment($asset, [
        'amount' => $amount,
        'paid_at' => now()->toDateString(),
        'bank_account_id' => BankAccount::factory()->create()->id,
        ...$overrides,
    ], $finance ?? assetInstallmentUser('FINANCE'));
}

// ── RBAC ─────────────────────────────────────────────────────────────────

test('CEO, PM, Finance and Logistics can view the installment list and detail', function (string $role) {
    $asset = installmentAsset();
    Asset::factory()->create(); // no plan — not listed
    $user = assetInstallmentUser($role);

    $this->actingAs($user)->get(route('finance.assetInstallments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Finance/AssetInstallments/Index')
            ->has('assets.data', 1)
            ->where('assets.data.0.id', $asset->id)
            ->where('assets.data.0.remaining_install', '12000000.00'));

    $this->actingAs($user)->get(route('finance.assetInstallments.show', $asset))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Finance/AssetInstallments/Show')
            ->where('asset.id', $asset->id)
            ->has('asset.installment_payments', 0));
})->with(['CEO', 'PM', 'FINANCE', 'LOGISTICS']);

test('roles outside the Asset Inventory row cannot see installments', function (string $role) {
    $asset = installmentAsset();
    $user = assetInstallmentUser($role);

    $this->actingAs($user)->get(route('finance.assetInstallments.index'))->assertForbidden();
    $this->actingAs($user)->get(route('finance.assetInstallments.show', $asset))->assertForbidden();
})->with(['MARKETING', 'DESIGNER', 'ESTIMATOR', 'QA', 'FIELD_STAFF']);

test('only Finance can record an installment payment', function (string $role) {
    $asset = installmentAsset();

    $this->actingAs(assetInstallmentUser($role))->post(route('finance.assetInstallments.storePayment', $asset), [
        'amount' => 1_000_000,
        'paid_at' => now()->toDateString(),
        'bank_account_id' => BankAccount::factory()->create()->id,
    ])->assertForbidden();

    expect(AssetInstallmentPayment::count())->toBe(0)
        ->and(FinanceTransaction::count())->toBe(0)
        ->and((float) $asset->fresh()->paid_install)->toBe(0.0);
})->with(['CEO', 'PM', 'LOGISTICS', 'FIELD_STAFF']);

test('payment controls and bank accounts are only sent to Finance', function () {
    installmentAsset();
    BankAccount::factory()->create();

    $this->actingAs(assetInstallmentUser('FINANCE'))->get(route('finance.assetInstallments.index'))
        ->assertInertia(fn (Assert $page) => $page->where('canPay', true)->has('bankAccounts', 1));

    $this->actingAs(assetInstallmentUser('LOGISTICS'))->get(route('finance.assetInstallments.index'))
        ->assertInertia(fn (Assert $page) => $page->where('canPay', false)->has('bankAccounts', 0));
});

test('an asset without an installment plan has no installment page', function () {
    $asset = Asset::factory()->create();

    $this->actingAs(assetInstallmentUser('FINANCE'))
        ->get(route('finance.assetInstallments.show', $asset))
        ->assertNotFound();
});

test('the installment ledger has no edit or delete route', function () {
    $methods = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'asset-installments'))
        ->flatMap(fn ($route) => $route->methods());

    expect($methods->intersect(['PUT', 'PATCH', 'DELETE'])->all())->toBe([]);
});

// ── Recording a payment ──────────────────────────────────────────────────

test('a payment writes the ledger row, an ANGSURAN expense and the audit together', function () {
    $finance = assetInstallmentUser('FINANCE');
    $bank = BankAccount::factory()->create();
    $asset = installmentAsset(12_000_000, 1_000_000);

    $this->actingAs($finance)->post(route('finance.assetInstallments.storePayment', $asset), [
        'amount' => 1_000_000,
        'paid_at' => now()->subDay()->toDateString(),
        'bank_account_id' => $bank->id,
        'note' => 'Cicilan ke-1',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $payment = AssetInstallmentPayment::sole();
    $transaction = FinanceTransaction::sole();

    expect((float) $payment->amount)->toBe(1_000_000.0)
        ->and($payment->paid_at->toDateString())->toBe(now()->subDay()->toDateString())
        ->and($payment->bank_account_id)->toBe($bank->id)
        ->and($payment->finance_transaction_id)->toBe($transaction->id)
        ->and($payment->note)->toBe('Cicilan ke-1')
        ->and($payment->created_by)->toBe($finance->id)
        ->and($transaction->type)->toBe(FinanceTransactionType::Expense)
        ->and($transaction->kategori)->toBe(FinanceCategory::Angsuran)
        ->and((float) $transaction->amount)->toBe(1_000_000.0)
        ->and($transaction->bank_account_id)->toBe($bank->id)
        ->and($transaction->project_id)->toBeNull()
        ->and((int) $transaction->reference_id)->toBe($asset->id)
        ->and($transaction->description)->toBe('Cicilan Mobil Pickup L300')
        ->and($transaction->date->toDateString())->toBe(now()->subDay()->toDateString());

    $asset->refresh();
    expect((float) $asset->paid_install)->toBe(1_000_000.0)
        ->and($asset->remaining_install)->toBe('11000000.00')
        ->and($asset->installment_status)->toBe(Asset::INSTALLMENT_ONGOING);

    $audit = AuditLog::where('action', 'finance.asset_installment_paid')->sole();
    expect($audit->model_type)->toBe('Asset')
        ->and($audit->model_id)->toBe($asset->id)
        ->and($audit->user_id)->toBe($finance->id)
        ->and((float) $audit->old_values['paid_install'])->toBe(0.0)
        ->and((float) $audit->new_values['paid_install'])->toBe(1_000_000.0)
        ->and($audit->new_values['finance_transaction_id'])->toBe($transaction->id)
        ->and(AuditLog::where('action', 'finance.transaction_created')->count())->toBe(1);
});

test('an over-payment is rejected and nothing is written', function () {
    $finance = assetInstallmentUser('FINANCE');
    $asset = installmentAsset(3_000_000, 1_000_000);

    $this->actingAs($finance)->post(route('finance.assetInstallments.storePayment', $asset), [
        'amount' => 3_000_001,
        'paid_at' => now()->toDateString(),
        'bank_account_id' => BankAccount::factory()->create()->id,
    ])->assertSessionHasErrors('amount');

    expect(AssetInstallmentPayment::count())->toBe(0)
        ->and(FinanceTransaction::count())->toBe(0)
        ->and(AuditLog::count())->toBe(0)
        ->and((float) $asset->fresh()->paid_install)->toBe(0.0);
});

test('an asset without a plan cannot receive payments', function () {
    $asset = Asset::factory()->create();

    $this->actingAs(assetInstallmentUser('FINANCE'))->post(route('finance.assetInstallments.storePayment', $asset), [
        'amount' => 100_000,
        'paid_at' => now()->toDateString(),
        'bank_account_id' => BankAccount::factory()->create()->id,
    ])->assertSessionHasErrors(['amount' => 'Aset ini tidak punya rencana cicilan.']);

    expect(AssetInstallmentPayment::count())->toBe(0)->and(FinanceTransaction::count())->toBe(0);
});

test('a payment needs an active bank account, a positive amount and a date not in the future', function () {
    $finance = assetInstallmentUser('FINANCE');
    $asset = installmentAsset();
    $inactive = BankAccount::factory()->create(['is_active' => false]);

    $this->actingAs($finance)->post(route('finance.assetInstallments.storePayment', $asset), [
        'amount' => 0,
        'paid_at' => now()->addDay()->toDateString(),
    ])->assertSessionHasErrors([
        'amount' => 'Nominal pembayaran harus lebih dari 0.',
        'paid_at' => 'Tanggal bayar tidak boleh di masa depan.',
        'bank_account_id' => 'Rekening sumber wajib dipilih.',
    ]);

    $this->actingAs($finance)->post(route('finance.assetInstallments.storePayment', $asset), [
        'amount' => 100_000,
        'paid_at' => now()->toDateString(),
        'bank_account_id' => $inactive->id,
    ])->assertSessionHasErrors(['bank_account_id' => 'Rekening bank tidak ditemukan atau tidak aktif.']);

    // The service re-checks the account itself, not only the Form Request.
    expect(fn () => payInstallment($asset, 100_000, $finance, ['bank_account_id' => $inactive->id]))
        ->toThrow(ValidationException::class);

    expect(AssetInstallmentPayment::count())->toBe(0)->and(FinanceTransaction::count())->toBe(0);
});

test('the service rejects a non-positive amount', function () {
    payInstallment(installmentAsset(), 0);
})->throws(ValidationException::class, 'Nominal pembayaran harus lebih dari 0.');

test('paying the full remainder marks the plan LUNAS and closes it', function () {
    $finance = assetInstallmentUser('FINANCE');
    $asset = installmentAsset(2_500_000, 1_000_000);

    payInstallment($asset, 1_000_000, $finance);
    payInstallment($asset, 1_500_000, $finance);

    $asset->refresh();
    expect($asset->remaining_install)->toBe('0.00')
        ->and($asset->installment_status)->toBe(Asset::INSTALLMENT_PAID_OFF)
        ->and($asset->installmentPayments()->count())->toBe(2)
        ->and((float) FinanceTransaction::sum('amount'))->toBe(2_500_000.0);

    expect(fn () => payInstallment($asset, 1, $finance))
        ->toThrow(ValidationException::class, 'Cicilan aset ini sudah lunas.');
});

test('a stale second click cannot pay the same installment twice', function () {
    $finance = assetInstallmentUser('FINANCE');
    $asset = installmentAsset(1_000_000, 1_000_000);
    // Both "clicks" loaded the asset while nothing was paid yet.
    $staleCopy = Asset::findOrFail($asset->id);

    payInstallment($asset, 1_000_000, $finance);

    // The service re-reads the row under lock, so the stale copy's
    // paid_install = 0 doesn't matter.
    expect(fn () => payInstallment($staleCopy, 1_000_000, $finance))->toThrow(ValidationException::class);

    expect(AssetInstallmentPayment::count())->toBe(1)
        ->and(FinanceTransaction::count())->toBe(1)
        ->and((float) $asset->fresh()->paid_install)->toBe(1_000_000.0);
});

test('the ledger is append-only even through Eloquent', function () {
    $payment = payInstallment(installmentAsset(), 500_000);

    expect(fn () => $payment->update(['amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $payment->delete())->toThrow(LogicException::class)
        ->and((float) $payment->fresh()->amount)->toBe(500_000.0);
});

// ── Status & list ────────────────────────────────────────────────────────

test('JATUH_TEMPO once the due day passed this month with no payment this month', function () {
    $this->travelTo(now()->setDate(2026, 9, 20)->setTime(9, 0));
    $finance = assetInstallmentUser('FINANCE');

    $overdue = installmentAsset(10_000_000, 1_000_000, 10, ['name' => 'Scross']);
    $notDueYet = installmentAsset(10_000_000, 1_000_000, 25, ['name' => 'Laptop']);
    $noDueDay = installmentAsset(10_000_000, 1_000_000, null, ['name' => 'Kursi Kantor']);
    $paidLastMonth = installmentAsset(10_000_000, 1_000_000, 5, ['name' => 'Mobil Pickup']);
    payInstallment($paidLastMonth, 1_000_000, $finance, ['paid_at' => '2026-08-05']);

    expect($overdue->fresh()->installment_status)->toBe('JATUH_TEMPO')
        ->and($notDueYet->fresh()->installment_status)->toBe('BERJALAN')
        ->and($noDueDay->fresh()->installment_status)->toBe('BERJALAN')
        ->and($paidLastMonth->fresh()->installment_status)->toBe('JATUH_TEMPO')
        ->and(Asset::installmentOverdue()->pluck('name')->sort()->values()->all())->toBe(['Mobil Pickup', 'Scross']);

    // Paying this month clears it.
    payInstallment($overdue, 1_000_000, $finance);
    expect($overdue->fresh()->installment_status)->toBe('BERJALAN');

    $this->actingAs($finance)->get(route('finance.assetInstallments.index', ['status' => 'JATUH_TEMPO']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('assets.data', 1)
            ->where('assets.data.0.name', 'Mobil Pickup')
            ->where('assets.data.0.installment_status', 'JATUH_TEMPO')
            ->where('summary.overdueCount', 1));

    $this->actingAs($finance)->get(route('finance.assetInstallments.index', ['status' => 'BERJALAN']))
        ->assertInertia(fn (Assert $page) => $page->has('assets.data', 3));
});

test('the list summary adds up totals, what is left and what is due this month', function () {
    $finance = assetInstallmentUser('FINANCE');
    $pickup = installmentAsset(12_000_000, 1_000_000, 28, ['name' => 'Mobil Pickup']);
    $laptop = installmentAsset(3_000_000, null, null, ['name' => 'Laptop']);
    $chair = installmentAsset(900_000, 300_000, 28, ['name' => 'Kursi']);
    payInstallment($pickup, 1_000_000, $finance);    // paid this month — not due again
    payInstallment($chair, 900_000, $finance);        // LUNAS

    $this->actingAs($finance)->get(route('finance.assetInstallments.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('assets.data', 3)
            // Still being paid off first; LUNAS last.
            ->where('assets.data.2.name', 'Kursi')
            ->where('assets.data.2.installment_status', 'LUNAS')
            ->where('summary.totalInstall', 15_900_000)
            ->where('summary.totalPaid', 1_900_000)
            ->where('summary.totalRemaining', 14_000_000)
            ->where('summary.outstandingCount', 2)
            // Laptop has no planned amount — its whole remainder is due.
            ->where('summary.dueThisMonth', 3_000_000));

    $this->actingAs($finance)->get(route('finance.assetInstallments.index', ['status' => 'LUNAS', 'search' => 'kur']))
        ->assertInertia(fn (Assert $page) => $page->has('assets.data', 1)->where('assets.data.0.id', $chair->id));

    expect($laptop->fresh()->installment_status)->toBe('BERJALAN');
});

test('the detail page lists payments newest first with their bank account', function () {
    $finance = assetInstallmentUser('FINANCE');
    $asset = installmentAsset();
    payInstallment($asset, 1_000_000, $finance, ['paid_at' => now()->subMonth()->toDateString()]);
    $latest = payInstallment($asset, 1_000_000, $finance);

    $this->actingAs(assetInstallmentUser('CEO'))->get(route('finance.assetInstallments.show', $asset))
        ->assertInertia(fn (Assert $page) => $page
            ->has('asset.installment_payments', 2)
            ->where('asset.installment_payments.0.id', $latest->id)
            ->where('asset.installment_payments.0.bank_account.id', $latest->bank_account_id)
            ->where('asset.installment_payments.0.creator.id', $finance->id)
            ->where('asset.remaining_install', '10000000.00')
            ->where('canPay', false));
});
