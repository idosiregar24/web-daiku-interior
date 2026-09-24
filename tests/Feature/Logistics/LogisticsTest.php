<?php

use App\Enums\ProjectStatus;
use App\Enums\StockMovementType;
use App\Models\Asset;
use App\Models\Material;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;
use Database\Seeders\RoleSeeder;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function logisticsUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

// ── RBAC (PRD §7.1 Logistics rows) ───────────────────────────────────────

test('material master is readable by CEO, Estimator, PM and Logistics', function (string $role) {
    $this->actingAs(logisticsUser($role))->get(route('logistics.materials.index'))->assertOk();
})->with(['CEO', 'ESTIMATOR', 'PM', 'LOGISTICS']);

test('material master is closed to every other role', function (string $role) {
    $this->actingAs(logisticsUser($role))->get(route('logistics.materials.index'))->assertForbidden();
})->with(['MARKETING', 'DESIGNER', 'QA', 'FINANCE', 'FIELD_STAFF']);

test('the stock ledger is readable by CEO, PM and Logistics only', function (string $role, int $status) {
    $this->actingAs(logisticsUser($role))->get(route('logistics.stock-movements.index'))->assertStatus($status);
})->with([
    ['CEO', 200], ['PM', 200], ['LOGISTICS', 200],
    ['ESTIMATOR', 403], ['FINANCE', 403], ['FIELD_STAFF', 403],
]);

test('assets are readable by CEO, PM, Finance and Logistics only', function (string $role, int $status) {
    $this->actingAs(logisticsUser($role))->get(route('logistics.assets.index'))->assertStatus($status);
})->with([
    ['CEO', 200], ['PM', 200], ['FINANCE', 200], ['LOGISTICS', 200],
    ['ESTIMATOR', 403], ['MARKETING', 403], ['FIELD_STAFF', 403],
]);

test('only Logistics can write materials, stock and assets', function (string $role) {
    $user = logisticsUser($role);
    $material = Material::factory()->create();
    $asset = Asset::factory()->create();

    $this->actingAs($user)->post(route('logistics.materials.store'), [])->assertForbidden();
    $this->actingAs($user)->put(route('logistics.materials.update', $material), [])->assertForbidden();
    $this->actingAs($user)->delete(route('logistics.materials.destroy', $material))->assertForbidden();
    $this->actingAs($user)->post(route('logistics.materials.stockIn', $material), [])->assertForbidden();
    $this->actingAs($user)->post(route('logistics.materials.stockOut', $material), [])->assertForbidden();
    $this->actingAs($user)->post(route('logistics.assets.store'), [])->assertForbidden();
    $this->actingAs($user)->delete(route('logistics.assets.destroy', $asset))->assertForbidden();
})->with(['CEO', 'ESTIMATOR', 'PM', 'FINANCE']);

test('the write actions are only offered to Logistics in the UI props', function () {
    $this->actingAs(logisticsUser('PM'))->get(route('logistics.materials.index'))
        ->assertInertia(fn (Assert $page) => $page->where('canManage', false)->where('projects', []));

    $this->actingAs(logisticsUser('LOGISTICS'))->get(route('logistics.materials.index'))
        ->assertInertia(fn (Assert $page) => $page->where('canManage', true));
});

// ── Material master + margin ─────────────────────────────────────────────

test('logistics can create a material and its margin is computed', function () {
    $this->actingAs(logisticsUser('LOGISTICS'))->post(route('logistics.materials.store'), [
        'name' => 'Plywood 18mm',
        'unit' => 'lembar',
        'category' => 'Kayu',
        'cost_price' => 185000,
        'sell_price' => 240000,
        'min_stock' => 10,
        'stock' => 999, // not fillable — stock only moves through the ledger
    ])->assertRedirect();

    $material = Material::sole();
    expect($material->margin)->toBe(55000.0)
        ->and($material->margin_percent)->toBe(22.9)
        ->and($material->stock)->toBe(0);
});

test('the index can filter to low-stock materials only', function () {
    Material::factory()->create(['name' => 'Aman', 'stock' => 50, 'min_stock' => 10]);
    Material::factory()->create(['name' => 'Menipis', 'stock' => 3, 'min_stock' => 10]);

    $this->actingAs(logisticsUser('LOGISTICS'))->get(route('logistics.materials.index', ['low_stock' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('materials.data', 1)
            ->where('materials.data.0.name', 'Menipis')
            ->where('materials.data.0.is_low_stock', true)
            ->where('summary.lowStockCount', 1));
});

test('a material with stock history cannot be deleted', function () {
    $material = Material::factory()->create();
    app(StockService::class)->stockIn($material, ['qty' => 5], logisticsUser('LOGISTICS'));

    $this->actingAs(logisticsUser('LOGISTICS'))
        ->delete(route('logistics.materials.destroy', $material))
        ->assertSessionHasErrors('material');

    expect(Material::whereKey($material->id)->exists())->toBeTrue();
});

test('an unused material can be deleted', function () {
    $material = Material::factory()->create();

    $this->actingAs(logisticsUser('LOGISTICS'))->delete(route('logistics.materials.destroy', $material))->assertRedirect();

    expect(Material::count())->toBe(0);
});

// ── Stock management ─────────────────────────────────────────────────────

test('stock in raises stock and writes a ledger row', function () {
    $logistics = logisticsUser('LOGISTICS');
    $material = Material::factory()->create(['stock' => 10]);

    $this->actingAs($logistics)->post(route('logistics.materials.stockIn', $material), [
        'qty' => 15,
        'movement_date' => now()->toDateString(),
        'note' => 'PO-001',
    ])->assertRedirect();

    $movement = StockMovement::sole();
    expect($material->fresh()->stock)->toBe(25)
        ->and($movement->type)->toBe(StockMovementType::In)
        ->and($movement->stock_after)->toBe(25)
        ->and($movement->project_id)->toBeNull()
        ->and($movement->recorded_by)->toBe($logistics->id);
});

test('stock in refuses a project reference', function () {
    $material = Material::factory()->create();

    $this->actingAs(logisticsUser('LOGISTICS'))->post(route('logistics.materials.stockIn', $material), [
        'qty' => 1,
        'movement_date' => now()->toDateString(),
        'project_id' => Project::factory()->create()->id,
    ])->assertSessionHasErrors('project_id');
});

test('stock out must be tied to a project', function () {
    $material = Material::factory()->create(['stock' => 10]);

    $this->actingAs(logisticsUser('LOGISTICS'))->post(route('logistics.materials.stockOut', $material), [
        'qty' => 2,
        'movement_date' => now()->toDateString(),
    ])->assertSessionHasErrors('project_id');

    expect($material->fresh()->stock)->toBe(10);
});

test('stock out lowers stock and accumulates the project usage', function () {
    $material = Material::factory()->create(['stock' => 30]);
    $project = Project::factory()->create();
    ProjectMaterial::factory()->create(['project_id' => $project->id, 'material_id' => $material->id, 'qty_planned' => 20]);
    $logistics = logisticsUser('LOGISTICS');

    foreach ([4, 6] as $qty) {
        $this->actingAs($logistics)->post(route('logistics.materials.stockOut', $material), [
            'qty' => $qty,
            'movement_date' => now()->toDateString(),
            'project_id' => $project->id,
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    $plan = ProjectMaterial::sole();
    expect($material->fresh()->stock)->toBe(20)
        ->and($plan->qty_used)->toBe(10)
        ->and($plan->qty_planned)->toBe(20)
        // Ledger and running total reconcile.
        ->and((int) StockMovement::where('type', 'OUT')->sum('qty'))->toBe(10);
});

test('stock out for an unplanned material creates the project row on the fly', function () {
    $material = Material::factory()->create(['stock' => 5]);
    $project = Project::factory()->create();

    app(StockService::class)->stockOut($material, $project, ['qty' => 2], logisticsUser('LOGISTICS'));

    $plan = ProjectMaterial::sole();
    expect($plan->qty_planned)->toBe(0)->and($plan->qty_used)->toBe(2);
});

test('stock can never go negative', function () {
    $material = Material::factory()->create(['stock' => 3]);
    $project = Project::factory()->create();

    $this->actingAs(logisticsUser('LOGISTICS'))->post(route('logistics.materials.stockOut', $material), [
        'qty' => 4,
        'movement_date' => now()->toDateString(),
        'project_id' => $project->id,
    ])->assertSessionHasErrors('qty');

    expect($material->fresh()->stock)->toBe(3)
        ->and(StockMovement::count())->toBe(0)
        ->and(ProjectMaterial::count())->toBe(0);
});

test('stock out is refused for a completed or cancelled project', function (ProjectStatus $status) {
    $material = Material::factory()->create(['stock' => 10]);
    $project = Project::factory()->create(['status' => $status->value]);

    expect(fn () => app(StockService::class)->stockOut($material, $project, ['qty' => 1], logisticsUser('LOGISTICS')))
        ->toThrow(ValidationException::class);
})->with([ProjectStatus::Completed, ProjectStatus::Cancelled]);

test('crossing below minimum stock alerts Logistics exactly once', function () {
    $logistics = logisticsUser('LOGISTICS');
    $material = Material::factory()->create(['stock' => 12, 'min_stock' => 10]);
    $project = Project::factory()->create();
    $service = app(StockService::class);

    $service->stockOut($material, $project, ['qty' => 2], $logistics);   // 10 — not below yet
    $service->stockOut($material, $project, ['qty' => 1], $logistics);   // 9 — crosses
    $service->stockOut($material, $project, ['qty' => 1], $logistics);   // 8 — already low

    expect(Notification::where('user_id', $logistics->id)->where('type', 'material_low_stock')->count())->toBe(1);
});

test('future movement dates are rejected', function () {
    $material = Material::factory()->create();

    $this->actingAs(logisticsUser('LOGISTICS'))->post(route('logistics.materials.stockIn', $material), [
        'qty' => 1,
        'movement_date' => now()->addDay()->toDateString(),
    ])->assertSessionHasErrors('movement_date');
});

// ── Project materials (PRD §4.8 "Kebutuhan Material Proyek") ─────────────

test('estimator, PM and logistics can plan project materials', function (string $role) {
    $project = Project::factory()->create();
    $material = Material::factory()->create();

    $this->actingAs(logisticsUser($role))->post(route('projects.materials.store', $project), [
        'material_id' => $material->id,
        'qty_planned' => 12,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(ProjectMaterial::sole()->qty_planned)->toBe(12);
})->with(['ESTIMATOR', 'PM', 'LOGISTICS']);

test('planning the same material twice updates instead of duplicating', function () {
    $project = Project::factory()->create();
    $material = Material::factory()->create();
    $pm = logisticsUser('PM');

    foreach ([5, 9] as $qty) {
        $this->actingAs($pm)->post(route('projects.materials.store', $project), ['material_id' => $material->id, 'qty_planned' => $qty]);
    }

    expect(ProjectMaterial::count())->toBe(1)->and(ProjectMaterial::sole()->qty_planned)->toBe(9);
});

test('project material permissions follow the matrix split', function () {
    $plan = ProjectMaterial::factory()->create();

    $this->actingAs(logisticsUser('FINANCE'))->post(route('projects.materials.store', $plan->project_id), [])->assertForbidden();
    $this->actingAs(logisticsUser('ESTIMATOR'))->put(route('project-materials.update', $plan), ['qty_planned' => 3])->assertForbidden();
    $this->actingAs(logisticsUser('PM'))->delete(route('project-materials.destroy', $plan))->assertForbidden();
    $this->actingAs(logisticsUser('PM'))->put(route('project-materials.update', $plan), ['qty_planned' => 3])->assertRedirect();

    expect($plan->fresh()->qty_planned)->toBe(3);
});

test('a plan with usage cannot be removed', function () {
    $material = Material::factory()->create(['stock' => 10]);
    $project = Project::factory()->create();
    app(StockService::class)->stockOut($material, $project, ['qty' => 1], logisticsUser('LOGISTICS'));

    $this->actingAs(logisticsUser('LOGISTICS'))
        ->delete(route('project-materials.destroy', ProjectMaterial::sole()))
        ->assertSessionHasErrors('qty_used');

    expect(ProjectMaterial::count())->toBe(1);
});

test('the project page shows the material tab only to roles that can read it', function (string $role, bool $visible) {
    $project = Project::factory()->create();
    ProjectMaterial::factory()->create(['project_id' => $project->id]);

    $this->actingAs(logisticsUser($role))->get(route('projects.show', $project))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canViewMaterials', $visible)
            ->has('projectMaterials', $visible ? 1 : 0));
})->with([
    ['CEO', true], ['PM', true], ['LOGISTICS', true], ['ESTIMATOR', true],
    ['FINANCE', false], ['QA', false], ['MARKETING', false],
]);

// ── Assets ───────────────────────────────────────────────────────────────

test('logistics can create, update and delete an asset', function () {
    $logistics = logisticsUser('LOGISTICS');

    $this->actingAs($logistics)->post(route('logistics.assets.store'), [
        'name' => 'Mobil Pickup L300',
        'category' => 'Kendaraan',
        'purchase_date' => '2024-03-01',
        'value' => 180000000,
        'condition' => 'GOOD',
        'location' => 'Workshop',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $asset = Asset::sole();

    $this->actingAs($logistics)->put(route('logistics.assets.update', $asset), [
        ...$asset->only(['name', 'category', 'location']),
        'purchase_date' => '2024-03-01',
        'value' => 170000000,
        'condition' => 'FAIR',
    ])->assertSessionHasNoErrors();

    expect($asset->fresh()->condition->value)->toBe('FAIR');

    $this->actingAs($logistics)->delete(route('logistics.assets.destroy', $asset))->assertRedirect();
    expect(Asset::count())->toBe(0);
});

test('asset condition must be one of the schema values', function () {
    $this->actingAs(logisticsUser('LOGISTICS'))->post(route('logistics.assets.store'), [
        'name' => 'Bor',
        'category' => 'Alat',
        'purchase_date' => '2024-03-01',
        'value' => 1000,
        'condition' => 'BROKEN',
    ])->assertSessionHasErrors('condition');
});

// ── Export ───────────────────────────────────────────────────────────────

test('material and asset lists export to Excel', function () {
    Material::factory()->count(2)->create();
    Asset::factory()->count(2)->create();
    $logistics = logisticsUser('LOGISTICS');

    $this->actingAs($logistics)->get(route('logistics.materials.export'))
        ->assertOk()
        ->assertDownload('daftar-material-'.now()->format('Y-m-d').'.xlsx');

    $this->actingAs($logistics)->get(route('logistics.assets.export'))
        ->assertOk()
        ->assertDownload('daftar-aset-'.now()->format('Y-m-d').'.xlsx');
});
