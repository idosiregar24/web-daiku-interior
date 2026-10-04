<?php

use App\Enums\StockMovementType;
use App\Models\AuditLog;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\MaterialSynonym;
use App\Models\ProjectMaterial;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\MaterialCatalogService;
use App\Services\StockService;
use Database\Seeders\MaterialCatalogSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(MaterialCatalogSeeder::class);
    $this->logistics = catalogUser('LOGISTICS');
    $this->catalog = app(MaterialCatalogService::class);
});

function catalogUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function triplek(array $overrides = []): array
{
    return [
        'material_category_id' => categoryId('KYP'),
        'base_name' => 'Triplek',
        'spec' => '17 mm 122×244',
        'brand' => 'Sengon Super',
        'unit_id' => unitId('lbr'),
        'cost_price' => 150_000,
        'sell_price' => 190_000,
        ...$overrides,
    ];
}

// ── Lapis 1–2: identitas terstruktur + match_key ─────────────────────────

test('a catalog item gets a generated display name and a per-category code', function () {
    $first = $this->catalog->create(triplek(), $this->logistics);
    $second = $this->catalog->create(triplek(['base_name' => 'MDF', 'spec' => '12mm', 'brand' => null, 'similar_reason' => null]), $this->logistics);
    $hardware = $this->catalog->create(triplek(['material_category_id' => categoryId('HDW'), 'base_name' => 'Engsel', 'spec' => null, 'brand' => null]), $this->logistics);

    expect($first->name)->toBe('Triplek 17 mm 122×244 — Sengon Super')
        ->and($first->code)->toBe('KYP-0001')
        ->and($second->code)->toBe('KYP-0002')
        ->and($hardware->code)->toBe('HDW-0001')
        ->and($first->match_key)->not->toBeNull();
});

test('the same item written differently is refused as already in the catalog', function (array $variant) {
    $this->catalog->create(triplek(), $this->logistics);

    expect(fn () => $this->catalog->create(triplek($variant), $this->logistics))
        ->toThrow(ValidationException::class, 'Barang ini sudah ada di katalog: KYP-0001');
})->with([
    'case & spaces' => [['base_name' => '  TRIPLEK ', 'spec' => '17MM  122 x 244', 'brand' => 'sengon super']],
    'unit glued' => [['spec' => '17mm 122x244']],
    'word order' => [['spec' => '122*244 17 mm']],
    'synonym' => [['base_name' => 'Plywood']],
    'punctuation' => [['spec' => '17-mm, 122×244.', 'brand' => 'Sengon-Super']],
]);

test('a different unit or category is a different item', function () {
    $this->catalog->create(triplek(), $this->logistics);

    $this->catalog->create(triplek(['unit_id' => unitId('m2'), 'similar_reason' => 'Dijual per m²']), $this->logistics);
    $this->catalog->create(triplek(['material_category_id' => categoryId('LLN'), 'similar_reason' => 'Bekas pakai']), $this->logistics);

    expect(Material::count())->toBe(3);
});

test('the database itself refuses a second row with the same match key', function () {
    $item = $this->catalog->create(triplek(), $this->logistics);

    expect(fn () => DB::table('materials')->insert([
        'material_category_id' => $item->material_category_id, 'code' => 'KYP-9999', 'name' => 'x', 'base_name' => 'x',
        'unit_id' => $item->unit_id, 'cost_price' => 1, 'sell_price' => 1, 'match_key' => $item->match_key,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

// ── Lapis 3: barang mirip ────────────────────────────────────────────────

test('a similar item needs a reason, which is audited', function () {
    $this->catalog->create(triplek(), $this->logistics);

    expect(fn () => $this->catalog->create(triplek(['spec' => '18 mm 122×244']), $this->logistics))
        ->toThrow(ValidationException::class, 'Barang serupa sudah ada: KYP-0001');

    $item = $this->catalog->create(triplek(['spec' => '18 mm 122×244', 'similar_reason' => 'Ketebalan beda']), $this->logistics);

    $log = AuditLog::where('action', 'logistics.material_created_despite_similar')->sole();
    expect($item->code)->toBe('KYP-0002')
        ->and($log->new_values['reason'])->toBe('Ketebalan beda')
        ->and($log->new_values['similar'][0])->toContain('KYP-0001');
});

test('similar items also catch a typo in the base name', function () {
    $this->catalog->create(triplek(), $this->logistics);

    $similar = $this->catalog->findSimilar(['base_name' => 'Tripleks', 'material_category_id' => categoryId('KYP')]);
    $typo = $this->catalog->findSimilar(['base_name' => 'Triplke']);
    $unrelated = $this->catalog->findSimilar(['base_name' => 'Engsel sendok']);

    expect($similar)->toHaveCount(1)->and($typo)->toHaveCount(1)->and($unrelated)->toHaveCount(0);
});

test('the material form posts the structured identity and asks for a reason on a similar item', function () {
    $this->catalog->create(triplek(), $this->logistics);
    $payload = [...triplek(['spec' => '18 mm']), 'min_stock' => 5];

    $this->actingAs($this->logistics)->post(route('logistics.materials.store'), $payload)->assertSessionHasErrors('similar_reason');
    $this->actingAs($this->logistics)->post(route('logistics.materials.store'), [...$payload, 'similar_reason' => 'Beda tebal'])->assertSessionHasNoErrors();

    $this->actingAs($this->logistics)->getJson(route('logistics.materials.similar', [
        'base_name' => 'plywood', 'spec' => '17mm 122x244', 'brand' => 'Sengon Super',
        'material_category_id' => categoryId('KYP'), 'unit_id' => unitId('lbr'),
    ]))->assertOk()->assertJsonPath('exact_id', Material::where('code', 'KYP-0001')->value('id'))->assertJsonCount(2, 'items');
});

// ── Lapis 5: satu pintu ──────────────────────────────────────────────────

test('only Logistics creates, looks up, checks and merges catalog items', function (string $role) {
    $user = catalogUser($role);
    $material = Material::factory()->create();

    $this->actingAs($user)->post(route('logistics.materials.store'), triplek())->assertForbidden();
    $this->actingAs($user)->get(route('logistics.materials.similar', ['base_name' => 'x']))->assertForbidden();
    $this->actingAs($user)->get(route('logistics.materials.duplicates'))->assertForbidden();
    $this->actingAs($user)->post(route('logistics.materials.merge', $material), ['target_id' => $material->id])->assertForbidden();
})->with(['PM', 'ESTIMATOR', 'CEO', 'FIELD_STAFF']);

// ── Lapis 6: gabung barang ───────────────────────────────────────────────

test('merging B into A moves stock through the ledger, redirects project lines and keeps B as an inactive record', function () {
    $a = $this->catalog->create(triplek(), $this->logistics);
    $b = $this->catalog->create(triplek(['spec' => '17 mm', 'similar_reason' => 'Lolos dobel']), $this->logistics);
    $stock = app(StockService::class);
    $stock->stockIn($a, ['qty' => 3], $this->logistics);
    $stock->stockIn($b, ['qty' => 2.5], $this->logistics);
    $line = ProjectMaterial::factory()->create(['material_id' => $b->id, 'unit_id' => $b->unit_id]);

    $this->actingAs($this->logistics)->post(route('logistics.materials.merge', $b), ['target_id' => $a->id])->assertSessionHasNoErrors();

    $a->refresh();
    $b->refresh();
    expect($a->stock)->toBe(5.5)
        ->and($b->stock)->toBe(0.0)
        ->and($b->is_active)->toBeFalse()
        ->and($b->merged_into_id)->toBe($a->id)
        ->and($b->match_key)->toBeNull()
        ->and($line->fresh()->material_id)->toBe($a->id)
        ->and(StockMovement::where('type', StockMovementType::MergeOut->value)->where('material_id', $b->id)->sole()->qty)->toBe(2.5)
        ->and(StockMovement::where('type', StockMovementType::MergeIn->value)->where('material_id', $a->id)->sole()->stock_after)->toBe(5.5)
        ->and(AuditLog::where('action', 'logistics.material_merged')->sole()->new_values['project_lines_redirected'])->toBe(1);

    // Merged items leave the working catalog.
    $this->actingAs($this->logistics)->get(route('logistics.materials.index'))
        ->assertInertia(fn (Assert $page) => $page->has('materials.data', 1));
});

test('items with different units cannot be merged, nor an inactive one', function () {
    $a = $this->catalog->create(triplek(), $this->logistics);
    $b = $this->catalog->create(triplek(['unit_id' => unitId('m2'), 'similar_reason' => 'x']), $this->logistics);

    $this->actingAs($this->logistics)->post(route('logistics.materials.merge', $b), ['target_id' => $a->id])
        ->assertSessionHasErrors('target_id');

    $c = $this->catalog->create(triplek(['spec' => '17mm', 'similar_reason' => 'x']), $this->logistics);
    $this->catalog->merge($c, $a, $this->logistics);

    $this->actingAs($this->logistics)->post(route('logistics.materials.merge', $c), ['target_id' => $a->id])
        ->assertSessionHasErrors('target_id');
});

// ── Data lama + sinonim baru: ditandai, tidak gagal ──────────────────────

test('a new synonym flags items that now turn out to be the same, for the Cek Duplikat page', function () {
    MaterialSynonym::query()->delete();
    $catalog = app(MaterialCatalogService::class);
    $multiplek = $catalog->create(triplek(['base_name' => 'Multiplek', 'brand' => null]), $this->logistics);
    $multiplex = $catalog->create(triplek(['base_name' => 'Multiplex', 'brand' => null, 'similar_reason' => 'Ejaan supplier']), $this->logistics);

    $superadmin = catalogUser('SUPERADMIN');
    $this->actingAs($superadmin)->post(route('master-data.material-synonyms.store'), ['term' => ' MULTIPLEX ', 'canonical' => 'Multiplek'])
        ->assertSessionHasNoErrors();

    expect(MaterialSynonym::sole()->term)->toBe('multiplex')
        ->and($multiplek->fresh()->possible_duplicate)->toBeFalse()
        ->and($multiplex->fresh()->possible_duplicate)->toBeTrue();

    $this->actingAs($this->logistics)->get(route('logistics.materials.duplicates'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('groups', 1)
            ->where('groups.0.keeper.id', $multiplek->id)
            ->where('groups.0.duplicates.0.id', $multiplex->id));

    // Merging the flagged one clears the group.
    $this->catalog->merge($multiplex->fresh(), $multiplek->fresh(), $this->logistics);
    expect($this->catalog->duplicateGroups())->toHaveCount(0);
});

test('the Sprint 11 catalog migration structures legacy items and flags colliding ones instead of failing', function () {
    $migration = require database_path('migrations/2026_10_04_212845_add_catalog_identity_to_materials_table.php');
    $migration->down();

    $now = now();
    foreach ([['Triplek 17mm', 'Kayu'], ['triplek 17 MM', 'kayu'], ['Engsel', null]] as [$name, $category]) {
        DB::table('materials')->insert([
            'name' => $name, 'category' => $category, 'unit_id' => unitId('lbr'), 'cost_price' => 1, 'sell_price' => 1,
            'stock' => 0, 'min_stock' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    $migration->up();

    $kayu = MaterialCategory::where('name', 'Kayu')->sole();
    $materials = Material::orderBy('id')->get();
    expect($kayu->code_prefix)->toHaveLength(3)
        ->and($materials[0]->material_category_id)->toBe($kayu->id)
        ->and($materials[1]->material_category_id)->toBe($kayu->id)
        ->and($materials[0]->code)->toBe($kayu->code_prefix.'-0001')
        ->and($materials[1]->code)->toBe($kayu->code_prefix.'-0002')
        ->and($materials[0]->possible_duplicate)->toBeFalse()
        ->and($materials[1]->possible_duplicate)->toBeTrue()
        ->and($materials[2]->category->name)->toBe('Lain-lain')
        ->and($materials[0]->base_name)->toBe('Triplek 17mm');
});

// ── Data Master ──────────────────────────────────────────────────────────

test('superadmin manages material categories; one in use can only be deactivated', function () {
    $superadmin = catalogUser('SUPERADMIN');

    $this->actingAs($superadmin)->post(route('master-data.material-categories.store'), ['name' => 'Batu & Granit', 'code_prefix' => 'btg'])
        ->assertSessionHasNoErrors();
    $category = MaterialCategory::where('name', 'Batu & Granit')->sole();
    expect($category->code_prefix)->toBe('BTG');

    $this->actingAs($superadmin)->post(route('master-data.material-categories.store'), ['name' => 'Lain', 'code_prefix' => 'BTG'])
        ->assertSessionHasErrors('code_prefix');

    Material::factory()->create(['material_category_id' => $category->id]);
    $this->actingAs($superadmin)->delete(route('master-data.material-categories.destroy', $category))->assertSessionHasErrors('name');
    $this->actingAs($superadmin)->put(route('master-data.material-categories.update', $category), [
        'name' => 'Batu & Granit', 'code_prefix' => 'BTG', 'is_active' => false,
    ])->assertSessionHasNoErrors();

    expect($category->fresh()->is_active)->toBeFalse();

    // A deactivated category isn't offered for new items.
    $this->actingAs($this->logistics)->post(route('logistics.materials.store'), [...triplek(['material_category_id' => $category->id]), 'min_stock' => 0])
        ->assertSessionHasErrors('material_category_id');
});

test('only superadmin manages material categories and synonyms', function (string $role) {
    $user = catalogUser($role);

    $this->actingAs($user)->post(route('master-data.material-categories.store'), ['name' => 'X', 'code_prefix' => 'XX'])->assertForbidden();
    $this->actingAs($user)->post(route('master-data.material-synonyms.store'), ['term' => 'a', 'canonical' => 'b'])->assertForbidden();
})->with(['CEO', 'LOGISTICS', 'PM']);
