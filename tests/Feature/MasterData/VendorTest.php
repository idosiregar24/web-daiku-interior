<?php

use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\SupplierDebt;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function vendorUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

// ── RBAC (Sprint 11 Sub 2: CEO + SUPERADMIN) ─────────────────────────────

test('CEO and superadmin manage vendors', function (string $role) {
    $user = vendorUser($role);

    $this->actingAs($user)->get(route('master-data.vendors.index'))->assertOk();
    $this->actingAs($user)->post(route('master-data.vendors.store'), ['name' => "Vendor {$role}", 'type' => 'MATERIAL'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(Vendor::where('name', "Vendor {$role}")->value('created_by'))->toBe($user->id);
})->with(['CEO', 'SUPERADMIN']);

test('other roles cannot open or change vendors', function (string $role) {
    $user = vendorUser($role);
    $vendor = Vendor::factory()->create();

    $this->actingAs($user)->get(route('master-data.vendors.index'))->assertForbidden();
    $this->actingAs($user)->post(route('master-data.vendors.store'), ['name' => 'X', 'type' => 'MATERIAL'])->assertForbidden();
    $this->actingAs($user)->put(route('master-data.vendors.update', $vendor), ['name' => 'X', 'type' => 'MATERIAL'])->assertForbidden();
    $this->actingAs($user)->delete(route('master-data.vendors.destroy', $vendor))->assertForbidden();
})->with(['FINANCE', 'LOGISTICS', 'PM', 'ESTIMATOR', 'MARKETING']);

// ── CRUD rules ───────────────────────────────────────────────────────────

test('vendor names are unique after collapsing whitespace', function () {
    $ceo = vendorUser('CEO');
    Vendor::factory()->create(['name' => 'Kaca Jaya']);

    $this->actingAs($ceo)->post(route('master-data.vendors.store'), ['name' => '  Kaca   Jaya ', 'type' => 'MATERIAL'])
        ->assertSessionHasErrors(['name' => 'Vendor dengan nama ini sudah ada.']);
});

test('the CEO edits a vendor, including deactivating it', function () {
    $ceo = vendorUser('CEO');
    $vendor = Vendor::factory()->create(['name' => 'Ideal']);

    $this->actingAs($ceo)->put(route('master-data.vendors.update', $vendor), [
        'name' => 'Ideal',
        'type' => 'JASA',
        'bank_name' => 'BCA',
        'bank_account_number' => '1234567890',
        'is_active' => false,
    ])->assertSessionHasNoErrors();

    $vendor->refresh();
    expect($vendor->type)->toBe('JASA')->and($vendor->bank_name)->toBe('BCA')->and($vendor->is_active)->toBeFalse();
});

test('a vendor in use cannot be deleted; an unused one can', function (string $usage) {
    $ceo = vendorUser('CEO');
    $used = Vendor::factory()->create();
    $unused = Vendor::factory()->create();

    match ($usage) {
        'debt' => SupplierDebt::factory()->create(['vendor_id' => $used->id]),
        'material' => ProjectMaterial::factory()->purchased()->create(['vendor_id' => $used->id]),
    };

    $this->actingAs($ceo)->delete(route('master-data.vendors.destroy', $used))->assertSessionHasErrors('name');
    $this->actingAs($ceo)->delete(route('master-data.vendors.destroy', $unused))->assertSessionHasNoErrors();

    expect(Vendor::whereKey($used->id)->exists())->toBeTrue()
        ->and(Vendor::whereKey($unused->id)->exists())->toBeFalse();

    $this->actingAs($ceo)->get(route('master-data.vendors.index'))
        ->assertInertia(fn (Assert $page) => $page->has('vendors.data', 1)->where('vendors.data.0.in_use', true));
})->with(['debt', 'material']);

// ── Supplier debts use the master ────────────────────────────────────────

test('a supplier debt needs an active vendor', function () {
    $finance = vendorUser('FINANCE');
    $inactive = Vendor::factory()->inactive()->create();

    $this->actingAs($finance)->post(route('finance.supplierDebts.store'), ['vendor_id' => $inactive->id, 'total_amount' => 100_000])
        ->assertSessionHasErrors(['vendor_id' => 'Vendor belum terdaftar atau sudah nonaktif — minta CEO menambahkannya di Data Master → Vendor.']);

    $this->actingAs($finance)->get(route('finance.supplierDebts.create'))
        ->assertInertia(fn (Assert $page) => $page->has('vendors', 0));
});

test('the supplier debt search matches the vendor name', function () {
    SupplierDebt::factory()->create(['vendor_id' => vendorId('Kaca Jaya')]);
    SupplierDebt::factory()->create(['vendor_id' => vendorId('Ideal')]);

    $this->actingAs(vendorUser('FINANCE'))->get(route('finance.supplierDebts.index', ['search' => 'kaca']))
        ->assertInertia(fn (Assert $page) => $page->has('debts.data', 1)->where('debts.data.0.vendor.name', 'Kaca Jaya'));
});

test('the Sprint 11 vendor migration turns supplier names into vendors and back', function () {
    $migration = require database_path('migrations/2026_10_04_184714_replace_supplier_name_with_vendor_id_on_supplier_debts.php');
    $migration->down();

    $creator = User::factory()->create();
    $project = Project::factory()->create();
    foreach (['Kaca Jaya', ' kaca  jaya', 'Ideal'] as $name) {
        DB::table('supplier_debts')->insert([
            'supplier_name' => $name, 'total_amount' => 1000, 'paid_amount' => 0, 'project_id' => $project->id,
            'created_by' => $creator->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $migration->up();

    expect(Schema::hasColumn('supplier_debts', 'supplier_name'))->toBeFalse()
        ->and(Vendor::count())->toBe(2)
        ->and(SupplierDebt::with('vendor')->orderBy('id')->get()->pluck('vendor.name')->all())->toBe(['Kaca Jaya', 'Kaca Jaya', 'Ideal']);

    $migration->down();

    expect(DB::table('supplier_debts')->orderBy('id')->pluck('supplier_name')->all())->toBe(['Kaca Jaya', 'Kaca Jaya', 'Ideal']);

    $migration->up();
});
