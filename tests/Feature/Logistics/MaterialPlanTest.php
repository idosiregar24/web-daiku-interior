<?php

use App\Enums\MaterialOrderStatus;
use App\Enums\MaterialRequestStatus;
use App\Enums\ProjectMaterialSource;
use App\Enums\ProjectPhase;
use App\Models\AuditLog;
use App\Models\Material;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\User;
use App\Services\ActionInboxService;
use App\Support\NotificationTarget;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 29 Sub 11 (E1–E5) — the Pengawas's / Admin Proyek's Rencana Bahan:
 * planned as drafts (server-picked source, no stock or prices for them),
 * "Ajukan ke Logistik", Logistik's Setujui / Tolak (custom items through the
 * catalog review), "Dipesan", then received — one status everywhere
 * (ProjectMaterial::orderStatus()).
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->pm = planUser('PM');
    $this->otherPm = planUser('PM');
    $this->qa = planUser('QA');
    $this->otherQa = planUser('QA');
    $this->admin = planUser('ADMIN_PROYEK');
    $this->logistics = planUser('LOGISTICS');
    $this->ceo = planUser('CEO');
    $this->project = Project::factory()->create(['pm_id' => $this->pm->id, 'phase' => ProjectPhase::Tm->value]);
    $this->project->supervisors()->attach($this->qa->id);
    $this->plenty = Material::factory()->create(['name' => 'Triplek 18mm', 'stock' => 50]);
    $this->scarce = Material::factory()->create(['name' => 'HPL Taco', 'stock' => 1]);
});

function planUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function planNotified(User $user, string $type): int
{
    return Notification::where('user_id', $user->id)->where('type', $type)->count();
}

/** A catalog line of the Rencana Bahan, planned by `$by` (the project's Pengawas by default). */
function planLine(object $test, Material $material, float $qty, ?User $by = null): ProjectMaterial
{
    $test->actingAs($by ?? $test->qa)->post(route('projects.materials.store', $test->project), ['material_id' => $material->id, 'qty_planned' => $qty])
        ->assertSessionHasNoErrors();

    return ProjectMaterial::where('material_id', $material->id)->latest('id')->firstOrFail();
}

function customPlan(array $overrides = []): array
{
    return ['name' => 'Engsel sendok slow motion', 'spec' => 'Blum 110°', 'qty' => 8, 'unit_id' => unitId('pcs'), 'note' => 'Untuk kabinet dapur', ...$overrides];
}

test('the Pengawas plans drafts; the server picks the source; no stock or prices reach them (E1/E2)', function () {
    // The source sent is ignored — stock 50 ≥ 10 → Gudang; stock 1 < 5 → Pembelian.
    $plenty = planLine($this, $this->plenty, 10);
    $this->actingAs($this->qa)->post(route('projects.materials.store', $this->project), ['material_id' => $this->scarce->id, 'source' => 'GUDANG', 'qty_planned' => 5])
        ->assertSessionHasNoErrors();
    $scarce = ProjectMaterial::where('material_id', $this->scarce->id)->sole();

    expect($plenty->request_channel)->toBe(ProjectMaterial::CHANNEL_RENCANA)
        ->and($plenty->request_status)->toBe(MaterialRequestStatus::Direncanakan)
        ->and($plenty->source)->toBe(ProjectMaterialSource::Gudang)
        ->and($scarce->source)->toBe(ProjectMaterialSource::Pembelian)
        ->and($plenty->orderStatus())->toBe(MaterialOrderStatus::Direncanakan);

    // Planning the same item again while it's a draft changes that line, no duplicate.
    planLine($this, $this->plenty, 12);
    expect(ProjectMaterial::where('material_id', $this->plenty->id)->count())->toBe(1)
        ->and((float) $plenty->fresh()->qty_planned)->toBe(12.0);

    // Another QA can't plan here; nobody told Logistik yet.
    $this->actingAs($this->otherQa)->post(route('projects.materials.store', $this->project), ['material_id' => $this->plenty->id, 'source' => 'GUDANG', 'qty_planned' => 1])->assertForbidden();
    expect(planNotified($this->logistics, 'material_plan_submitted'))->toBe(0);

    $this->actingAs($this->qa)->get(route('projects.show', $this->project))->assertInertia(fn (Assert $page) => $page
        ->where('materialPermissions.seeCost', false)
        ->where('materialPermissions.planThroughLogistics', true)
        ->missing('projectMaterials.0.material.stock')
        ->missing('projectMaterials.0.material.cost_price')
        ->missing('projectMaterials.0.cost_total')
        ->missing('projectMaterials.0.unit_price')
        ->where('projectMaterials.0.order_status', 'DIRENCANAKAN')
        ->where('execution.can.submitMaterialPlan', true)
        ->where('execution.meetingMaterials.0.editable', true)
        ->reloadOnly('materialOptions', fn (Assert $reload) => $reload->missing('materialOptions.0.stock')->missing('materialOptions.0.cost_price')));

    // The PM (and Logistik) still see stock and cost — and plan directly, not through Logistik.
    $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertInertia(fn (Assert $page) => $page
        ->where('materialPermissions.seeCost', true)
        ->where('materialPermissions.planThroughLogistics', false)
        ->has('projectMaterials.0.material.stock')
        ->where('execution.can.submitMaterialPlan', false)
        ->where('execution.meetingMaterials.0.editable', false)
        ->reloadOnly('materialOptions', fn (Assert $reload) => $reload->has('materialOptions.0.stock')));

    $direct = Material::factory()->create();
    $this->actingAs($this->pm)->post(route('projects.materials.store', $this->project), ['material_id' => $direct->id, 'source' => 'GUDANG', 'qty_planned' => 2])->assertSessionHasNoErrors();
    expect(ProjectMaterial::where('material_id', $direct->id)->sole())
        ->request_channel->toBeNull()
        ->request_status->toBe(MaterialRequestStatus::Disetujui);
});

test('custom lines, then "Ajukan ke Logistik": DIAJUKAN, one notification, one queue row per project (E2/E3)', function () {
    planLine($this, $this->plenty, 4);
    $this->actingAs($this->qa)->post(route('projects.material-plans.custom', $this->project), customPlan(['unit_id' => null]))->assertSessionHasErrors('unit_id');
    $this->actingAs($this->qa)->post(route('projects.material-plans.custom', $this->project), customPlan())->assertSessionHasNoErrors();
    // The Admin Proyek inputs on every project.
    $this->actingAs($this->admin)->post(route('projects.material-plans.custom', $this->project), customPlan(['name' => 'Lampu LED strip', 'qty' => 5, 'unit_id' => unitId('m')]))->assertSessionHasNoErrors();

    $custom = ProjectMaterial::where('custom_name', 'Engsel sendok slow motion')->sole();
    expect($custom->source)->toBe(ProjectMaterialSource::Custom)
        ->and($custom->material_id)->toBeNull()
        ->and($custom->request_status)->toBe(MaterialRequestStatus::Direncanakan)
        ->and($custom->requested_snapshot['name'])->toBe('Engsel sendok slow motion');

    // RBAC: another project's QA, the PM, Logistik and the CEO don't submit a Pengawas's plan.
    $this->actingAs($this->otherQa)->post(route('projects.material-plans.custom', $this->project), customPlan())->assertForbidden();
    $this->actingAs($this->otherQa)->post(route('projects.material-plans.submit', $this->project))->assertForbidden();
    $this->actingAs($this->pm)->post(route('projects.material-plans.submit', $this->project))->assertForbidden();
    $this->actingAs($this->logistics)->post(route('projects.material-plans.submit', $this->project))->assertForbidden();

    $this->actingAs($this->qa)->post(route('projects.material-plans.submit', $this->project))->assertSessionHasNoErrors();

    $lines = $this->project->projectMaterials()->get();
    expect($lines)->toHaveCount(3)
        ->and($lines->every(fn (ProjectMaterial $line) => $line->request_status === MaterialRequestStatus::Diajukan && $line->submitted_at !== null))->toBeTrue()
        ->and(planNotified($this->logistics, 'material_plan_submitted'))->toBe(1)
        ->and(AuditLog::where('action', 'logistics.material_plan_submitted')->count())->toBe(1);

    // The bell opens Pemesanan Bahan on that project.
    expect(NotificationTarget::for(Notification::where('user_id', $this->logistics->id)->sole()))
        ->toBe(route('logistics.material-orders.index', ['project_id' => $this->project->id]));

    // Nothing left to submit.
    $this->actingAs($this->qa)->post(route('projects.material-plans.submit', $this->project))->assertSessionHasErrors('plan');

    // One Logistik queue — per project — and the plan is not on Pengajuan Barang.
    $groups = collect(app(ActionInboxService::class)->for($this->logistics, true));
    expect($groups->firstWhere('key', 'material-plans'))
        ->count->toBe(1)
        ->routeName->toBe('logistics.material-orders.index')
        ->and($groups->firstWhere('key', 'material-plans')['items'][0])
        ->title->toBe("Pengajuan bahan · {$this->project->name}")
        ->subtitle->toBe('3 bahan')
        ->and($groups->firstWhere('key', 'material-logistics'))->toBeNull();

    $this->actingAs($this->logistics)->get(route('logistics.material-requests.index'))->assertInertia(fn (Assert $page) => $page->where('requests.total', 0));
    $this->actingAs($this->logistics)->get(route('logistics.material-orders.index'))->assertInertia(fn (Assert $page) => $page
        ->component('Logistics/MaterialOrders/Index')
        ->where('lines.total', 3)
        ->where('counts.DIAJUKAN', 3)
        ->where('permissions.decide', true));
});

test('drafts are the planners\' until submitted; then only Logistik changes them (E5)', function () {
    $line = planLine($this, $this->plenty, 4);
    $other = planLine($this, $this->scarce, 1, $this->admin);

    // The PM doesn't edit the Pengawas's draft; another QA neither.
    $this->actingAs($this->pm)->put(route('project-materials.update', $line), ['qty_planned' => 9])->assertForbidden();
    $this->actingAs($this->otherQa)->put(route('project-materials.update', $line), ['qty_planned' => 9])->assertForbidden();

    // Pengawas and Admin Proyek edit a draft — the source follows the amount (stock 50 < 60 → Pembelian).
    $this->actingAs($this->qa)->put(route('project-materials.update', $line), ['qty_planned' => 60])->assertSessionHasNoErrors();
    expect($line->fresh())->qty_planned->toEqual(60)->source->toBe(ProjectMaterialSource::Pembelian);
    $this->actingAs($this->admin)->delete(route('project-materials.destroy', $other))->assertSessionHasNoErrors();
    expect($other->fresh())->toBeNull();

    $this->actingAs($this->qa)->post(route('projects.material-plans.submit', $this->project))->assertSessionHasNoErrors();

    $this->actingAs($this->qa)->put(route('project-materials.update', $line), ['qty_planned' => 3])->assertForbidden();
    $this->actingAs($this->qa)->delete(route('project-materials.destroy', $line))->assertForbidden();
    $this->actingAs($this->admin)->put(route('project-materials.update', $line), ['qty_planned' => 3])->assertForbidden();

    $this->actingAs($this->qa)->get(route('projects.show', $this->project))->assertInertia(fn (Assert $page) => $page
        ->where('execution.meetingMaterials.0.order_status', 'DIAJUKAN')
        ->where('execution.meetingMaterials.0.editable', false));

    $this->actingAs($this->logistics)->put(route('project-materials.update', $line), ['qty_planned' => 30])->assertSessionHasNoErrors();
    expect((float) $line->fresh()->qty_planned)->toBe(30.0);
});

test('Logistik: Setujui / Tolak per row or all, custom through the catalog review, Dipesan, Diterima (E3/E4)', function () {
    $plenty = planLine($this, $this->plenty, 10);
    $scarce = planLine($this, $this->scarce, 5);
    $refused = planLine($this, Material::factory()->create(['name' => 'Cat duco', 'stock' => 0]), 2);
    $this->actingAs($this->qa)->post(route('projects.material-plans.custom', $this->project), customPlan());
    $custom = ProjectMaterial::whereNull('material_id')->sole();
    $this->actingAs($this->qa)->post(route('projects.material-plans.submit', $this->project));
    $vendor = vendorId('Toko Bangunan Jaya');

    // Only Logistik decides.
    $this->actingAs($this->qa)->post(route('logistics.material-orders.decide'), ['ids' => [$plenty->id], 'decision' => 'approve'])->assertForbidden();
    $this->actingAs($this->pm)->post(route('logistics.material-orders.decide'), ['ids' => [$plenty->id], 'decision' => 'approve'])->assertForbidden();
    $this->actingAs($this->admin)->post(route('logistics.material-orders.decide'), ['ids' => [$plenty->id], 'decision' => 'approve'])->assertForbidden();

    // A custom item is never approved in bulk — it goes through the catalog review.
    $this->actingAs($this->logistics)->post(route('logistics.material-orders.decide'), ['ids' => [$plenty->id, $custom->id], 'decision' => 'approve'])->assertSessionHasErrors('decision');
    expect($plenty->fresh()->request_status)->toBe(MaterialRequestStatus::Diajukan);

    // Setujui sekaligus; Logistik may set the vendor of the bought line.
    $this->actingAs($this->logistics)->post(route('logistics.material-orders.decide'), ['ids' => [$plenty->id, $scarce->id], 'decision' => 'approve', 'vendor_id' => $vendor])->assertSessionHasNoErrors();
    expect($plenty->fresh())->request_status->toBe(MaterialRequestStatus::Disetujui)->source->toBe(ProjectMaterialSource::Gudang)->vendor_id->toBeNull()
        ->and($scarce->fresh())->vendor_id->toBe($vendor)->reviewed_by->toBe($this->logistics->id)
        ->and($scarce->fresh()->orderStatus())->toBe(MaterialOrderStatus::Disetujui);

    // Tolak needs a reason; the Pengawas hears it.
    $this->actingAs($this->logistics)->post(route('logistics.material-orders.decide'), ['ids' => [$refused->id], 'decision' => 'reject'])->assertSessionHasErrors('reason');
    $this->actingAs($this->logistics)->post(route('logistics.material-orders.decide'), ['ids' => [$refused->id], 'decision' => 'reject', 'reason' => 'Pakai cat yang ada di gudang'])->assertSessionHasNoErrors();
    expect($refused->fresh())->request_status->toBe(MaterialRequestStatus::Ditolak)->reject_reason->toBe('Pakai cat yang ada di gudang')
        ->and(planNotified($this->qa, 'material_plan_rejected'))->toBe(1)
        ->and(NotificationTarget::for(Notification::where('user_id', $this->qa->id)->where('type', 'material_plan_rejected')->sole()))
        ->toBe(route('projects.show', ['project' => $this->project->id, 'tab' => 'material']));
    // A decided line can't be decided again.
    $this->actingAs($this->logistics)->post(route('logistics.material-orders.decide'), ['ids' => [$refused->id], 'decision' => 'approve'])->assertSessionHasErrors('decision');

    // The custom line: Logistik's catalog review (golden rule #10) — approved as a one-off item.
    $this->actingAs($this->logistics)->post(route('logistics.material-requests.review', $custom), [
        'decision' => ProjectMaterial::DECISION_CUSTOM, 'name' => 'Engsel sendok Blum', 'unit_id' => unitId('pcs'), 'qty' => 8, 'unit_price' => 35_000,
    ])->assertSessionHasNoErrors();
    expect($custom->fresh())->request_status->toBe(MaterialRequestStatus::Disetujui)->source->toBe(ProjectMaterialSource::Custom);

    // Dipesan: bought lines only, a future date; the Pengawas hears the expected arrival.
    $expected = now()->addDays(3)->toDateString();
    $this->actingAs($this->qa)->post(route('logistics.material-orders.order'), ['ids' => [$scarce->id], 'expected_at' => $expected])->assertForbidden();
    $this->actingAs($this->logistics)->post(route('logistics.material-orders.order'), ['ids' => [$scarce->id], 'expected_at' => now()->subDay()->toDateString()])->assertSessionHasErrors('expected_at');
    $this->actingAs($this->logistics)->post(route('logistics.material-orders.order'), ['ids' => [$plenty->id], 'expected_at' => $expected])->assertSessionHasErrors('ids');
    $this->actingAs($this->logistics)->post(route('logistics.material-orders.order'), ['ids' => [$scarce->id, $custom->id], 'expected_at' => $expected, 'note' => 'PO-112'])->assertSessionHasNoErrors();

    expect($scarce->fresh())
        ->ordered_by->toBe($this->logistics->id)
        ->order_note->toBe('PO-112')
        ->and($scarce->fresh()->ordered_at)->not->toBeNull()
        ->and($scarce->fresh()->expected_at->toDateString())->toBe($expected)
        ->and($scarce->fresh()->orderStatus())->toBe(MaterialOrderStatus::Dipesan)
        ->and(planNotified($this->qa, 'material_ordered'))->toBe(1)
        ->and(AuditLog::where('action', 'logistics.material_ordered')->count())->toBe(2);

    // Diterima sebagian → lengkap (the Pengawas hears "lengkap" once); from the warehouse → Dari gudang.
    $this->actingAs($this->logistics)->post(route('project-materials.purchase', $scarce), ['qty' => 2, 'unit_price' => 250_000, 'purchase_date' => now()->toDateString()])->assertSessionHasNoErrors();
    expect($scarce->fresh()->orderStatus())->toBe(MaterialOrderStatus::Sebagian)
        ->and(planNotified($this->qa, 'material_received'))->toBe(0);
    $this->actingAs($this->logistics)->post(route('project-materials.purchase', $scarce), ['qty' => 3, 'unit_price' => 250_000, 'purchase_date' => now()->toDateString()])->assertSessionHasNoErrors();
    expect($scarce->fresh()->orderStatus())->toBe(MaterialOrderStatus::Lengkap)
        ->and(planNotified($this->qa, 'material_received'))->toBe(1);

    $this->actingAs($this->logistics)->post(route('project-materials.issue', $plenty), ['qty' => 10, 'movement_date' => now()->toDateString()])->assertSessionHasNoErrors();
    expect($plenty->fresh()->orderStatus())->toBe(MaterialOrderStatus::DariGudang)
        ->and(planNotified($this->qa, 'material_received'))->toBe(2);

    // One status, read the same on the project page.
    $this->actingAs($this->qa)->get(route('projects.show', $this->project))->assertInertia(fn (Assert $page) => $page
        ->where('projectMaterials', fn ($lines) => collect($lines)->pluck('order_status', 'id')->all() == [
            $plenty->id => 'DARI_GUDANG', $scarce->id => 'LENGKAP', $refused->id => 'DITOLAK', $custom->id => 'DIPESAN',
        ]));
});

test('Pemesanan Bahan: Logistik and the CEO see every project, a PM only theirs, QA never (RBAC)', function () {
    planLine($this, $this->plenty, 2);
    $this->actingAs($this->qa)->post(route('projects.material-plans.submit', $this->project));

    $this->actingAs($this->ceo)->get(route('logistics.material-orders.index'))->assertInertia(fn (Assert $page) => $page
        ->where('lines.total', 1)->where('permissions.decide', false));
    $this->actingAs($this->pm)->get(route('logistics.material-orders.index'))->assertInertia(fn (Assert $page) => $page->where('lines.total', 1));
    $this->actingAs($this->otherPm)->get(route('logistics.material-orders.index'))->assertInertia(fn (Assert $page) => $page->where('lines.total', 0));
    $this->actingAs($this->qa)->get(route('logistics.material-orders.index'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('logistics.material-orders.index'))->assertForbidden();
    $this->actingAs(planUser('FIELD_STAFF'))->get(route('logistics.material-orders.index'))->assertForbidden();

    // Status filters follow orderStatus().
    $this->actingAs($this->logistics)->get(route('logistics.material-orders.index', ['status' => 'DIPESAN']))->assertInertia(fn (Assert $page) => $page->where('lines.total', 0));
    $this->actingAs($this->logistics)->get(route('logistics.material-orders.index', ['status' => 'DIAJUKAN', 'project_id' => $this->project->id]))->assertInertia(fn (Assert $page) => $page->where('lines.total', 1));
});

test('a submitted plan line that waits keeps the project from completing; a draft does not', function () {
    planLine($this, $this->plenty, 2);
    expect($this->project->projectMaterials()->pendingRequest()->count())->toBe(0);

    $this->actingAs($this->qa)->post(route('projects.material-plans.submit', $this->project));
    expect($this->project->projectMaterials()->pendingRequest()->count())->toBe(1);
});
