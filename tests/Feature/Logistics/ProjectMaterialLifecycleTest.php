<?php

use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\StockMovementType;
use App\Models\AuditLog;
use App\Models\Material;
use App\Models\Milestone;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\QaForm;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ProjectMaterialService;
use App\Services\QaFormService;
use App\Services\StockService;
use Database\Seeders\RoleSeeder;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->logistics = materialUser('LOGISTICS');
    $this->pm = materialUser('PM');
    $this->project = Project::factory()->create(['pm_id' => $this->pm->id]);
});

function materialUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function purchaseLine(Project $project, Material $material, float $planned = 19): ProjectMaterial
{
    return ProjectMaterial::factory()->purchased()->create([
        'project_id' => $project->id,
        'material_id' => $material->id,
        'unit_id' => $material->unit_id,
        'qty_planned' => $planned,
    ]);
}

// ── §5.1: beli 19 → pakai 17 → retur 2 → proyek B ambil 2 ───────────────

test('the triplek story: bought 19, used 17, 2 returned and taken by the next project at the warehouse price', function () {
    $triplek = Material::factory()->create(['name' => 'Triplek 17mm', 'unit_id' => unitId('lbr'), 'stock' => 0, 'cost_price' => 150_000]);
    $line = purchaseLine($this->project, $triplek);
    $vendor = vendorId('Toko Sumber Kayu');

    $this->actingAs($this->pm)->post(route('project-materials.purchase', $line), [
        'qty' => 19, 'unit_price' => 160_000, 'vendor_id' => $vendor, 'purchase_date' => now()->toDateString(),
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->pm)->post(route('project-materials.usage', $line), ['qty' => 17])->assertSessionHasNoErrors();
    $this->actingAs($this->logistics)->post(route('project-materials.return', $line), [
        'qty' => 2, 'movement_date' => now()->toDateString(), 'note' => 'Sisa utuh',
    ])->assertSessionHasNoErrors();

    $line->refresh();
    expect($line->qty_received)->toBe(19.0)
        ->and($line->qty_used)->toBe(17.0)
        ->and($line->qty_returned)->toBe(2.0)
        ->and($line->leftover)->toBe(0.0)
        ->and($line->vendor_id)->toBe($vendor)
        // Decision #9: the return doesn't lower project A's cost.
        ->and((float) $line->cost_total)->toBe(19 * 160_000.0)
        ->and($triplek->fresh()->stock)->toBe(2.0);

    $return = StockMovement::where('type', StockMovementType::Return->value)->sole();
    expect($return->project_id)->toBe($this->project->id)
        ->and($return->project_material_id)->toBe($line->id)
        ->and($return->qty)->toBe(2.0);

    // Project B takes the 2 sheets — charged the warehouse price, not A's purchase price.
    $projectB = Project::factory()->create();
    $this->actingAs($this->logistics)->post(route('logistics.materials.stockOut', $triplek), [
        'qty' => 2, 'movement_date' => now()->toDateString(), 'project_id' => $projectB->id,
    ])->assertSessionHasNoErrors();

    $lineB = ProjectMaterial::where('project_id', $projectB->id)->sole();
    expect($lineB->source->value)->toBe('GUDANG')
        ->and($lineB->qty_received)->toBe(2.0)
        ->and((float) $lineB->cost_total)->toBe(300_000.0)
        ->and((float) StockMovement::where('type', 'OUT')->sole()->unit_cost)->toBe(150_000.0)
        ->and($triplek->fresh()->stock)->toBe(0.0)
        ->and((float) $line->fresh()->cost_total)->toBe(3_040_000.0);
});

test('a later warehouse price change never rewrites a recorded issue cost', function () {
    $material = Material::factory()->create(['stock' => 10, 'cost_price' => 100_000]);
    $line = ProjectMaterial::factory()->create(['project_id' => $this->project->id, 'material_id' => $material->id, 'unit_id' => $material->unit_id]);

    app(ProjectMaterialService::class)->issue($line, ['qty' => 2.5], $this->logistics);
    $material->update(['cost_price' => 999_000]);

    expect((float) $line->fresh()->cost_total)->toBe(250_000.0)
        ->and((float) StockMovement::sole()->unit_cost)->toBe(100_000.0);
});

// ── Rules on quantities ──────────────────────────────────────────────────

test('stock never goes negative when issuing to a project', function () {
    $material = Material::factory()->create(['stock' => 1.5]);
    $line = ProjectMaterial::factory()->create(['project_id' => $this->project->id, 'material_id' => $material->id, 'unit_id' => $material->unit_id]);

    $this->actingAs($this->logistics)->post(route('project-materials.issue', $line), [
        'qty' => 1.75, 'movement_date' => now()->toDateString(),
    ])->assertSessionHasErrors('qty');

    expect($material->fresh()->stock)->toBe(1.5)->and($line->fresh()->qty_received)->toBe(0.0);
});

test('usage and every settlement must fit in what is left', function () {
    $material = Material::factory()->create();
    $line = purchaseLine($this->project, $material);
    $service = app(ProjectMaterialService::class);
    $service->recordPurchase($line, ['qty' => 5, 'unit_price' => 1_000], $this->pm);

    $this->actingAs($this->pm)->post(route('project-materials.usage', $line), ['qty' => 5.01])->assertSessionHasErrors('qty');

    $service->recordUsage($line, ['qty' => 2.5], $this->pm);
    $service->recordWaste($line, ['qty' => 1, 'reason' => 'Retak saat dipotong'], $this->pm);

    expect(fn () => $service->handOverToClient($line, ['qty' => 1.51, 'note' => 'Sisa'], $this->pm))->toThrow(ValidationException::class);

    $service->handOverToClient($line, ['qty' => 1.5, 'note' => 'Potongan diminta klien'], $this->pm);

    $line->refresh();
    expect($line->leftover)->toBe(0.0)
        ->and($line->qty_wasted)->toBe(1.0)
        ->and($line->qty_handed_over)->toBe(1.5)
        ->and($line->waste_reason)->toContain('Retak saat dipotong')
        ->and($line->handover_note)->toContain('Potongan diminta klien')
        ->and(AuditLog::where('action', 'logistics.material_wasted')->count())->toBe(1)
        ->and(AuditLog::where('action', 'logistics.material_handed_over')->count())->toBe(1);
});

test('waste needs a reason and a hand-over needs a note', function () {
    $line = purchaseLine($this->project, Material::factory()->create());

    $this->actingAs($this->pm)->post(route('project-materials.waste', $line), ['qty' => 1])
        ->assertSessionHasErrors(['reason' => 'Alasan susut wajib diisi.']);
    $this->actingAs($this->pm)->post(route('project-materials.handOver', $line), ['qty' => 1])
        ->assertSessionHasErrors(['note' => 'Catatan penyerahan ke klien wajib diisi.']);
});

test('a purchase line is not issued from stock and a warehouse line is not purchased', function () {
    $material = Material::factory()->create(['stock' => 10]);
    $bought = purchaseLine($this->project, $material);
    $stocked = ProjectMaterial::factory()->create(['project_id' => $this->project->id, 'material_id' => $material->id, 'unit_id' => $material->unit_id]);
    $service = app(ProjectMaterialService::class);

    expect(fn () => $service->issue($bought, ['qty' => 1], $this->logistics))->toThrow(ValidationException::class)
        ->and(fn () => $service->recordPurchase($stocked, ['qty' => 1, 'unit_price' => 1], $this->logistics))->toThrow(ValidationException::class);
});

test('only approved lines move', function () {
    $line = purchaseLine($this->project, Material::factory()->create());
    $line->forceFill(['request_status' => 'DIAJUKAN'])->save();

    $this->actingAs($this->pm)->post(route('project-materials.purchase', $line), [
        'qty' => 1, 'unit_price' => 1_000, 'purchase_date' => now()->toDateString(),
    ])->assertSessionHasErrors(['qty' => 'Baris material ini belum disetujui Logistik.']);
});

test('a cancelled project receives nothing more but can still settle its leftovers', function () {
    $material = Material::factory()->create();
    $line = purchaseLine($this->project, $material);
    $service = app(ProjectMaterialService::class);
    $service->recordPurchase($line, ['qty' => 3, 'unit_price' => 1_000], $this->pm);
    $this->project->update(['status' => ProjectStatus::Cancelled->value]);

    expect(fn () => $service->recordPurchase($line->fresh(), ['qty' => 1, 'unit_price' => 1_000], $this->pm))->toThrow(ValidationException::class);

    $service->returnToWarehouse($line, ['qty' => 3, 'movement_date' => now()->toDateString()], $this->logistics);
    expect($material->fresh()->stock)->toBe(53.0);
});

test('a custom item must be mapped onto a same-unit catalog item before returning to stock', function () {
    $line = ProjectMaterial::factory()->custom('Kaca potong 8mm')->create(['project_id' => $this->project->id, 'unit_id' => unitId('lbr')]);
    $service = app(ProjectMaterialService::class);
    $service->recordPurchase($line, ['qty' => 2, 'unit_price' => 250_000], $this->pm);

    expect(fn () => $service->returnToWarehouse($line, ['qty' => 1], $this->logistics))
        ->toThrow(ValidationException::class, 'Barang custom wajib dipetakan');

    $otherUnit = Material::factory()->create(['unit_id' => unitId('m2')]);
    expect(fn () => $service->returnToWarehouse($line, ['qty' => 1, 'material_id' => $otherUnit->id], $this->logistics))
        ->toThrow(ValidationException::class);

    $glass = Material::factory()->create(['unit_id' => unitId('lbr'), 'stock' => 0]);
    $service->returnToWarehouse($line, ['qty' => 1, 'material_id' => $glass->id], $this->logistics);

    expect($glass->fresh()->stock)->toBe(1.0)
        ->and(StockMovement::sole()->material_id)->toBe($glass->id)
        ->and($line->fresh()->display_name)->toBe('Kaca potong 8mm');
});

// ── RBAC (§5.6) ──────────────────────────────────────────────────────────

test('another project\'s PM cannot record anything on this project', function (string $routeName, array $payload) {
    $line = purchaseLine($this->project, Material::factory()->create());

    $this->actingAs(materialUser('PM'))->post(route($routeName, $line), $payload)->assertForbidden();
})->with([
    ['project-materials.purchase', ['qty' => 1, 'unit_price' => 1, 'purchase_date' => '2026-01-01']],
    ['project-materials.usage', ['qty' => 1]],
    ['project-materials.waste', ['qty' => 1, 'reason' => 'x']],
    ['project-materials.handOver', ['qty' => 1, 'note' => 'x']],
]);

test('issuing and returning are Logistics-only, even for the project\'s own PM', function (string $role) {
    $line = ProjectMaterial::factory()->create(['project_id' => $this->project->id]);
    $user = $role === 'PM' ? $this->pm : materialUser($role);

    $this->actingAs($user)->post(route('project-materials.issue', $line), ['qty' => 1, 'movement_date' => now()->toDateString()])->assertForbidden();
    $this->actingAs($user)->post(route('project-materials.return', $line), ['qty' => 1, 'movement_date' => now()->toDateString()])->assertForbidden();
})->with(['PM', 'ESTIMATOR', 'CEO', 'FINANCE']);

test('the project page offers lifecycle actions per role', function () {
    ProjectMaterial::factory()->create(['project_id' => $this->project->id]);

    $this->actingAs($this->pm)->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page
            ->where('materialPermissions.receive', true)
            ->where('materialPermissions.issue', false)
            ->where('materialPermissions.return', false)
            ->has('projectMaterials.0.leftover'));

    $this->actingAs(materialUser('PM'))->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page->where('materialPermissions.receive', false)->where('materialPermissions.create', false));

    $this->actingAs($this->logistics)->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page->where('materialPermissions.issue', true)->where('materialPermissions.return', true));
});

// ── Block COMPLETED while leftovers remain (decision #8) ─────────────────

test('a project with unsettled leftovers stays ACTIVE after its last QA approval, and completes once they are settled', function () {
    $material = Material::factory()->create(['name' => 'Triplek 17mm', 'unit_id' => unitId('lbr')]);
    $line = purchaseLine($this->project, $material);
    $service = app(ProjectMaterialService::class);
    $service->recordPurchase($line, ['qty' => 19, 'unit_price' => 160_000], $this->pm);
    $service->recordUsage($line, ['qty' => 17], $this->pm);

    $milestone = Milestone::factory()->create(['project_id' => $this->project->id, 'status' => MilestoneStatus::QaWaiting->value, 'order' => 1]);
    $qaForm = QaForm::factory()->create(['project_id' => $this->project->id, 'milestone_id' => $milestone->id]);
    app(QaFormService::class)->review($qaForm, 'approve', [['label' => 'OK', 'passed' => true, 'note' => null]], null, materialUser('QA'));

    $notice = Notification::where('user_id', $this->pm->id)->where('type', 'project_material_leftover')->sole();
    expect($this->project->fresh()->status)->toBe(ProjectStatus::Active)
        ->and($milestone->fresh()->status)->toBe(MilestoneStatus::Completed)
        ->and($notice->message)->toContain('Triplek 17mm (2 lbr)')
        ->and(Notification::where('user_id', $this->logistics->id)->where('type', 'project_material_leftover')->exists())->toBeTrue();

    $service->recordWaste($line, ['qty' => 1, 'reason' => 'Retak'], $this->pm);
    expect($this->project->fresh()->status)->toBe(ProjectStatus::Active);

    $service->returnToWarehouse($line, ['qty' => 1, 'movement_date' => now()->toDateString()], $this->logistics);
    expect($this->project->fresh()->status)->toBe(ProjectStatus::Completed)
        ->and(Notification::where('user_id', $this->pm->id)->where('type', 'project_completed')->exists())->toBeTrue();
});

test('a project with no leftovers still completes on its last QA approval', function () {
    $material = Material::factory()->create();
    $line = purchaseLine($this->project, $material);
    $service = app(ProjectMaterialService::class);
    $service->recordPurchase($line, ['qty' => 2.5, 'unit_price' => 10_000], $this->pm);
    $service->recordUsage($line, ['qty' => 2.5], $this->pm);

    $milestone = Milestone::factory()->create(['project_id' => $this->project->id, 'status' => MilestoneStatus::QaWaiting->value, 'order' => 1]);
    $qaForm = QaForm::factory()->create(['project_id' => $this->project->id, 'milestone_id' => $milestone->id]);
    app(QaFormService::class)->review($qaForm, 'approve', [['label' => 'OK', 'passed' => true, 'note' => null]], null, materialUser('QA'));

    expect($this->project->fresh()->status)->toBe(ProjectStatus::Completed);
});

// ── Stock ledger page (Sub 3) ────────────────────────────────────────────

test('the stock ledger filters RETURN rows and shows the project they came from', function () {
    $material = Material::factory()->create();
    $line = purchaseLine($this->project, $material);
    $service = app(ProjectMaterialService::class);
    $service->recordPurchase($line, ['qty' => 2, 'unit_price' => 1_000], $this->pm);
    $service->returnToWarehouse($line, ['qty' => 2, 'movement_date' => now()->toDateString()], $this->logistics);
    app(StockService::class)->stockIn($material, ['qty' => 5], $this->logistics);

    $this->actingAs($this->logistics)->get(route('logistics.stock-movements.index', ['type' => 'RETURN']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('movements.data', 1)
            ->where('movements.data.0.type', 'RETURN')
            ->where('movements.data.0.project.name', $this->project->name));
});
