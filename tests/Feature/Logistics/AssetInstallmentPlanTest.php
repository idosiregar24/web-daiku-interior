<?php

use App\Exports\AssetsExport;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\User;
use App\Services\AssetInstallmentService;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function assetPlanUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** A valid asset form payload (Logistics' AssetFormDialog). */
function assetPayload(array $overrides = []): array
{
    return [
        'name' => 'Mobil Pickup L300',
        'category' => 'Kendaraan',
        'purchase_date' => '2025-03-01',
        'value' => 185_000_000,
        'condition' => 'GOOD',
        'location' => 'Workshop',
        ...$overrides,
    ];
}

function planPayload(array $overrides = []): array
{
    return assetPayload([
        'has_installment' => true,
        'total_install' => 120_000_000,
        'installment_amount' => 5_000_000,
        'installment_due_day' => 10,
        ...$overrides,
    ]);
}

function recordAssetPayment(Asset $asset, float $amount): void
{
    app(AssetInstallmentService::class)->recordPayment($asset, [
        'amount' => $amount,
        'paid_at' => now()->toDateString(),
        'bank_account_id' => BankAccount::factory()->create()->id,
    ], assetPlanUser('FINANCE'));
}

// ── RBAC: the plan is Logistics' (PRD §7.1 "Asset Inventory": LOG CRUD) ────

test('Finance, CEO and PM cannot set or change an installment plan', function (string $role) {
    $asset = Asset::factory()->withInstallmentPlan(10_000_000)->create();
    $user = assetPlanUser($role);

    $this->actingAs($user)->post(route('logistics.assets.store'), planPayload())->assertForbidden();
    $this->actingAs($user)->put(route('logistics.assets.update', $asset), planPayload(['total_install' => 1]))->assertForbidden();

    expect(Asset::count())->toBe(1)
        ->and((float) $asset->fresh()->total_install)->toBe(10_000_000.0);
})->with(['FINANCE', 'CEO', 'PM']);

// ── Creating / editing the plan ──────────────────────────────────────────

test('Logistics creates an asset with an installment plan, which is audited', function () {
    $logistics = assetPlanUser('LOGISTICS');

    $this->actingAs($logistics)->post(route('logistics.assets.store'), planPayload([
        'paid_install' => 50_000_000, // never fillable from the form
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $asset = Asset::sole();
    expect($asset->has_installment)->toBeTrue()
        ->and((float) $asset->total_install)->toBe(120_000_000.0)
        ->and((float) $asset->paid_install)->toBe(0.0)
        ->and((float) $asset->installment_amount)->toBe(5_000_000.0)
        ->and($asset->installment_due_day)->toBe(10)
        ->and($asset->remaining_install)->toBe('120000000.00');

    $audit = AuditLog::where('action', 'finance.asset_installment_plan_updated')->sole();
    expect($audit->user_id)->toBe($logistics->id)
        ->and($audit->old_values)->toBeNull()
        ->and($audit->new_values['has_installment'])->toBeTrue();
});

test('an asset without a plan keeps the old form working and writes no plan audit', function () {
    $this->actingAs(assetPlanUser('LOGISTICS'))
        ->post(route('logistics.assets.store'), assetPayload())
        ->assertSessionHasNoErrors();

    $asset = Asset::sole();
    expect($asset->has_installment)->toBeFalse()
        ->and($asset->total_install)->toBeNull()
        ->and($asset->remaining_install)->toBeNull()
        ->and(AuditLog::count())->toBe(0);
});

test('plan fields are dropped when the plan is off', function () {
    $this->actingAs(assetPlanUser('LOGISTICS'))->post(route('logistics.assets.store'), assetPayload([
        'has_installment' => false,
        'total_install' => 99,
        'installment_amount' => 999, // would fail lte:total_install if it were validated
        'installment_due_day' => 31,
    ]))->assertSessionHasNoErrors();

    $asset = Asset::sole();
    expect($asset->has_installment)->toBeFalse()
        ->and($asset->total_install)->toBeNull()
        ->and($asset->installment_amount)->toBeNull()
        ->and($asset->installment_due_day)->toBeNull();
});

test('the plan is validated with Indonesian messages', function () {
    $logistics = assetPlanUser('LOGISTICS');

    $this->actingAs($logistics)->post(route('logistics.assets.store'), planPayload(['total_install' => null]))
        ->assertSessionHasErrors(['total_install' => 'Total cicilan wajib diisi untuk aset bercicilan.']);

    $this->actingAs($logistics)->post(route('logistics.assets.store'), planPayload([
        'total_install' => 10_000_000,
        'installment_amount' => 12_000_000,
        'installment_due_day' => 31,
    ]))->assertSessionHasErrors([
        'installment_amount' => 'Cicilan per bulan tidak boleh melebihi total cicilan.',
        'installment_due_day' => 'Tanggal jatuh tempo harus antara 1 dan 28.',
    ]);

    $this->actingAs($logistics)->post(route('logistics.assets.store'), planPayload(['total_install' => 0]))
        ->assertSessionHasErrors(['total_install' => 'Total cicilan harus lebih dari 0.']);

    expect(Asset::count())->toBe(0);
});

test('Logistics can edit the plan while nothing is paid, and the change is audited', function () {
    $asset = Asset::factory()->withInstallmentPlan(10_000_000, 1_000_000, 10)->create();

    $this->actingAs(assetPlanUser('LOGISTICS'))
        ->put(route('logistics.assets.update', $asset), planPayload(['total_install' => 8_000_000, 'installment_due_day' => 15]))
        ->assertSessionHasNoErrors();

    $asset->refresh();
    expect((float) $asset->total_install)->toBe(8_000_000.0)
        ->and($asset->installment_due_day)->toBe(15);

    $audit = AuditLog::where('action', 'finance.asset_installment_plan_updated')->sole();
    expect((float) $audit->old_values['total_install'])->toBe(10_000_000.0)
        ->and((float) $audit->new_values['total_install'])->toBe(8_000_000.0);
});

test('editing only non-plan fields writes no plan audit', function () {
    $asset = Asset::factory()->withInstallmentPlan(120_000_000, 5_000_000, 10)->create();

    $this->actingAs(assetPlanUser('LOGISTICS'))
        ->put(route('logistics.assets.update', $asset), planPayload(['condition' => 'FAIR']))
        ->assertSessionHasNoErrors();

    expect($asset->fresh()->condition->value)->toBe('FAIR')
        ->and(AuditLog::where('action', 'finance.asset_installment_plan_updated')->count())->toBe(0);
});

test('the total cannot drop below what is already paid', function () {
    $asset = Asset::factory()->withInstallmentPlan(10_000_000, 1_000_000)->create();
    recordAssetPayment($asset, 4_000_000);

    $this->actingAs(assetPlanUser('LOGISTICS'))
        ->put(route('logistics.assets.update', $asset), planPayload(['total_install' => 3_999_999, 'installment_amount' => 1_000_000]))
        ->assertSessionHasErrors(['total_install' => 'Total cicilan tidak boleh lebih kecil dari yang sudah dibayar (Rp 4.000.000).']);

    expect((float) $asset->fresh()->total_install)->toBe(10_000_000.0);

    // Exactly what's paid is fine — the plan becomes LUNAS.
    $this->actingAs(assetPlanUser('LOGISTICS'))
        ->put(route('logistics.assets.update', $asset), planPayload(['total_install' => 4_000_000, 'installment_amount' => 1_000_000]))
        ->assertSessionHasNoErrors();

    expect($asset->fresh()->installment_status)->toBe(Asset::INSTALLMENT_PAID_OFF);
});

test('the plan cannot be switched off once something is paid', function () {
    $asset = Asset::factory()->withInstallmentPlan(10_000_000, 1_000_000)->create();
    recordAssetPayment($asset, 1_000_000);

    $this->actingAs(assetPlanUser('LOGISTICS'))
        ->put(route('logistics.assets.update', $asset), assetPayload(['has_installment' => false]))
        ->assertSessionHasErrors(['has_installment' => 'Cicilan aset ini sudah dibayar sebagian — rencana cicilan tidak bisa dihapus.']);

    // Omitting the flag (an older client) is the same as switching it off.
    $this->actingAs(assetPlanUser('LOGISTICS'))
        ->put(route('logistics.assets.update', $asset), assetPayload())
        ->assertSessionHasErrors('has_installment');

    $asset->refresh();
    expect($asset->has_installment)->toBeTrue()
        ->and((float) $asset->total_install)->toBe(10_000_000.0);
});

test('an unpaid plan can be switched off, clearing its fields', function () {
    $asset = Asset::factory()->withInstallmentPlan(10_000_000, 1_000_000, 10)->create();

    $this->actingAs(assetPlanUser('LOGISTICS'))
        ->put(route('logistics.assets.update', $asset), assetPayload(['has_installment' => false]))
        ->assertSessionHasNoErrors();

    $asset->refresh();
    expect($asset->has_installment)->toBeFalse()
        ->and($asset->total_install)->toBeNull()
        ->and($asset->installment_amount)->toBeNull()
        ->and($asset->installment_due_day)->toBeNull();
});

// ── Deleting ─────────────────────────────────────────────────────────────

test('an asset with installment payments cannot be deleted', function () {
    $asset = Asset::factory()->withInstallmentPlan(10_000_000)->create(['name' => 'Scross']);
    recordAssetPayment($asset, 1_000_000);

    $this->actingAs(assetPlanUser('LOGISTICS'))
        ->delete(route('logistics.assets.destroy', $asset))
        ->assertSessionHasErrors(['asset' => 'Aset Scross sudah punya riwayat pembayaran cicilan dan tidak bisa dihapus.']);

    expect(Asset::whereKey($asset->id)->exists())->toBeTrue()
        ->and($asset->installmentPayments()->count())->toBe(1);
});

test('an asset with an unpaid plan can still be deleted', function () {
    $asset = Asset::factory()->withInstallmentPlan(10_000_000)->create();

    $this->actingAs(assetPlanUser('LOGISTICS'))->delete(route('logistics.assets.destroy', $asset))->assertRedirect();

    expect(Asset::count())->toBe(0);
});

// ── Aset page & export ───────────────────────────────────────────────────

test('the Aset page shows what is left to pay', function () {
    $asset = Asset::factory()->withInstallmentPlan(10_000_000, 1_000_000)->create(['name' => 'A Pickup']);
    Asset::factory()->create(['name' => 'B Bor']);
    recordAssetPayment($asset, 2_500_000);

    $this->actingAs(assetPlanUser('PM'))->get(route('logistics.assets.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('assets.data.0.remaining_install', '7500000.00')
            ->where('assets.data.1.remaining_install', null)
            ->where('summary.installmentRemaining', 7_500_000)
            ->where('summary.installmentCount', 1));
});

test('the asset export carries the installment columns', function () {
    $asset = Asset::factory()->withInstallmentPlan(10_000_000, 1_000_000, 12)->create();
    recordAssetPayment($asset, 2_000_000);
    $export = new AssetsExport;

    expect($export->headings())->toContain('Total Cicilan', 'Terbayar', 'Sisa Cicilan', 'Cicilan per Bulan', 'Jatuh Tempo (Tgl)')
        ->and(array_slice($export->map($asset->fresh()), 7))->toBe(['Ya', 10_000_000.0, 2_000_000.0, 8_000_000.0, 1_000_000.0, 12])
        ->and(array_slice($export->map(Asset::factory()->create()), 7))->toBe(['Tidak', '-', '-', '-', '-', '-']);
});
