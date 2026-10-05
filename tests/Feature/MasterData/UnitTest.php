<?php

use App\Models\Material;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\SiteSetting;
use App\Models\Unit;
use App\Models\User;
use App\Services\QuotationService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->superadmin = User::factory()->create();
    $this->superadmin->assignRole('SUPERADMIN');
});

function unitUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

// ── Data Master → Satuan (Sprint 11 Sub 1) ───────────────────────────────

test('the units seeder creates the common units once', function () {
    $this->seed(UnitSeeder::class);
    $this->seed(UnitSeeder::class);

    expect(Unit::count())->toBe(count(Unit::DEFAULTS))
        ->and(Unit::where('code', 'lbr')->value('name'))->toBe('Lembar');
});

test('superadmin creates, edits and deletes an unused unit; codes are stored lower-case', function () {
    $this->actingAs($this->superadmin)->post(route('master-data.units.store'), [
        'code' => ' Roll ',
        'name' => 'Roll',
        'sort_order' => 20,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $unit = Unit::sole();
    expect($unit->code)->toBe('roll')->and($unit->is_active)->toBeTrue();

    $this->actingAs($this->superadmin)->put(route('master-data.units.update', $unit), [
        'code' => 'roll',
        'name' => 'Gulung',
        'sort_order' => 3,
        'is_active' => false,
    ])->assertSessionHasNoErrors();

    expect($unit->fresh()->name)->toBe('Gulung')->and($unit->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->superadmin)->delete(route('master-data.units.destroy', $unit))->assertRedirect();
    expect(Unit::count())->toBe(0);
});

test('a unit code must be unique', function () {
    Unit::factory()->create(['code' => 'lbr']);

    $this->actingAs($this->superadmin)->post(route('master-data.units.store'), ['code' => 'LBR', 'name' => 'Lembar'])
        ->assertSessionHasErrors(['code' => 'Kode satuan ini sudah ada.']);
});

test('a unit in use can only be deactivated, not deleted', function () {
    $unit = Unit::factory()->create();
    Material::factory()->create(['unit_id' => $unit->id]);

    $this->actingAs($this->superadmin)->delete(route('master-data.units.destroy', $unit))->assertSessionHasErrors('code');
    expect(Unit::whereKey($unit->id)->exists())->toBeTrue();

    $this->actingAs($this->superadmin)->get(route('master-data.index'))
        ->assertInertia(fn (Assert $page) => $page->where('units.0.in_use', true));
});

test('only superadmin manages units', function (string $role) {
    $unit = Unit::factory()->create();
    $user = unitUser($role);

    $this->actingAs($user)->post(route('master-data.units.store'), ['code' => 'x', 'name' => 'X'])->assertForbidden();
    $this->actingAs($user)->put(route('master-data.units.update', $unit), ['code' => 'x', 'name' => 'X'])->assertForbidden();
    $this->actingAs($user)->delete(route('master-data.units.destroy', $unit))->assertForbidden();
})->with(['CEO', 'LOGISTICS', 'ESTIMATOR', 'PM', 'FINANCE']);

// ── Units in materials and RAB lines ─────────────────────────────────────

test('a material takes an active unit; a deactivated unit is refused for new materials but kept on edit', function () {
    $logistics = unitUser('LOGISTICS');
    $inactive = Unit::factory()->inactive()->create(['code' => 'old']);
    $payload = ['material_category_id' => categoryId('KYP'), 'base_name' => 'Plywood 18mm', 'cost_price' => 185000, 'sell_price' => 240000, 'min_stock' => 2.5];

    $this->actingAs($logistics)->post(route('logistics.materials.store'), [...$payload, 'unit_id' => $inactive->id])
        ->assertSessionHasErrors(['unit_id' => 'Satuan old sudah dinonaktifkan — pilih satuan lain.']);

    $material = Material::factory()->create(['unit_id' => $inactive->id]);
    $this->actingAs($logistics)->put(route('logistics.materials.update', $material), [...$payload, 'unit_id' => $inactive->id])
        ->assertSessionHasNoErrors();

    expect($material->fresh()->min_stock)->toBe(2.5);
});

test('RAB lines take a unit and a fractional qty; the total is rounded to the cent', function () {
    $quotation = Quotation::factory()->create();

    app(QuotationService::class)->replaceItems($quotation, [
        ['description' => 'Lantai parket', 'qty' => 12.5, 'unit_id' => unitId('m2'), 'unit_price' => 333_333.33],
    ]);

    $item = QuotationItem::sole();
    expect($item->qty)->toBe(12.5)
        ->and($item->unit->code)->toBe('m2')
        ->and((float) $item->total_price)->toBe(4_166_666.63)
        ->and((float) $quotation->fresh()->total_amount)->toBe(4_166_666.63);
});

test('the RAB request refuses a qty with more than two decimals and a missing unit', function () {
    $estimator = unitUser('ESTIMATOR');
    $quotation = Quotation::factory()->create();

    $this->actingAs($estimator)->put(route('quotations.items.update', $quotation), [
        'items' => [['description' => 'Item', 'qty' => 1.255, 'unit_price' => 1000]],
    ])->assertSessionHasErrors(['items.0.qty', 'items.0.unit_id']);
});

test('the quotation PDF prints the unit code and a fractional qty', function () {
    $quotation = Quotation::factory()->create();
    QuotationItem::factory()->create(['quotation_id' => $quotation->id, 'qty' => 2.5, 'unit_id' => unitId('m2')]);

    $html = view('pdf.quotation', [
        'quotation' => $quotation->load(['lead', 'items']),
        'siteSettings' => SiteSetting::current(),
        'validityDays' => QuotationService::VALIDITY_DAYS,
    ])->render();

    expect($html)->toContain('<td class="text-right">2,5</td>')->toContain('<td>m2</td>');
});

// ── Backfill migration ───────────────────────────────────────────────────

test('the Sprint 11 unit migration maps legacy unit text onto master units and back', function () {
    $migration = require database_path('migrations/2026_10_04_183925_replace_unit_text_with_unit_id_on_materials_and_quotation_items.php');
    $migration->down();

    expect(Schema::hasColumn('materials', 'unit'))->toBeTrue();

    $now = now();
    foreach (['Lembar ', 'lbr', 'LEMBAR', 'Meter Persegi', 'Paket'] as $i => $text) {
        DB::table('materials')->insert([
            'name' => "Barang {$i}", 'unit' => $text, 'cost_price' => 1, 'sell_price' => 1,
            'stock' => 0, 'min_stock' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    $quotation = Quotation::factory()->create();
    DB::table('quotation_items')->insert([
        'quotation_id' => $quotation->id, 'description' => 'Partisi', 'qty' => 3, 'unit' => 'm²',
        'unit_price' => 100, 'total_price' => 300, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $migration->up();

    expect(Schema::hasColumn('materials', 'unit'))->toBeFalse()
        ->and(Material::with('unit')->get()->pluck('unit.code')->all())->toBe(['lbr', 'lbr', 'lbr', 'm2', 'paket'])
        ->and(Unit::where('code', 'paket')->value('name'))->toBe('Paket')
        ->and(Unit::where('code', 'lbr')->count())->toBe(1)
        ->and(QuotationItem::sole()->unit->code)->toBe('m2');
});
