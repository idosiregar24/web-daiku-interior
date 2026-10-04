<?php

use App\Enums\MaterialRequestStatus;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Models\AuditLog;
use App\Models\Material;
use App\Models\Milestone;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\Task;
use App\Models\User;
use App\Services\MaterialRequestService;
use App\Services\ProjectMaterialService;
use App\Services\ProjectService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->pm = requestUser('PM');
    $this->tukang = requestUser('FIELD_STAFF');
    $this->logistics = requestUser('LOGISTICS');
    $this->ceo = requestUser('CEO');
    $this->project = Project::factory()->create(['pm_id' => $this->pm->id]);
    Task::factory()->create(['project_id' => $this->project->id, 'assignee_id' => $this->tukang->id]);
});

function requestUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** A full PM/Estimator request (decision #13's required data). */
function teamRequest(array $overrides = []): array
{
    return [
        'name' => 'Kaca tempered 8mm',
        'spec' => 'Potong 80×120, sudut tumpul',
        'unit_id' => unitId('lbr'),
        'qty' => 2,
        'estimated_price' => 450_000,
        'reason' => 'Ukuran khusus, tidak ada di katalog',
        ...$overrides,
    ];
}

function submittedRequest(Project $project, User $by, array $data = []): ProjectMaterial
{
    return app(MaterialRequestService::class)->submit($project, teamRequest($data), $by);
}

// ── Jalur B: Tukang → PM → Logistik ──────────────────────────────────────

test('a Tukang request goes to the PM first, then Logistics, then becomes a custom line', function () {
    $this->actingAs($this->tukang)->post(route('projects.material-requests.store', $this->project), [
        'name' => 'Handle pintu panjang',
        'qty' => 4,
        'reason' => 'Handle lama patah',
    ])->assertSessionHasNoErrors();

    $line = ProjectMaterial::sole();
    expect($line->request_status)->toBe(MaterialRequestStatus::MenungguPm)
        ->and($line->request_channel)->toBe(ProjectMaterial::CHANNEL_TUKANG)
        ->and($line->unit_id)->toBeNull()
        ->and($line->submitted_at)->toBeNull()
        ->and(Notification::where('user_id', $this->pm->id)->where('type', 'material_request_pm_pending')->exists())->toBeTrue()
        ->and(Notification::where('user_id', $this->logistics->id)->exists())->toBeFalse();

    $this->actingAs($this->pm)->post(route('project-materials.pmDecision', $line), ['decision' => 'approve'])->assertSessionHasNoErrors();

    $line->refresh();
    expect($line->request_status)->toBe(MaterialRequestStatus::Diajukan)
        ->and($line->pm_reviewed_by)->toBe($this->pm->id)
        ->and($line->submitted_at)->not->toBeNull()
        ->and(Notification::where('user_id', $this->logistics->id)->where('type', 'material_request_submitted')->exists())->toBeTrue();

    // Logistics completes what the Tukang left out and changes the qty.
    $this->actingAs($this->logistics)->post(route('logistics.material-requests.review', $line), [
        'decision' => 'CUSTOM',
        'name' => 'Handle pintu aluminium 60cm',
        'spec' => 'Hitam doff',
        'unit_id' => unitId('pcs'),
        'qty' => 3,
        'unit_price' => 85_000,
    ])->assertSessionHasNoErrors();

    $line->refresh();
    expect($line->request_status)->toBe(MaterialRequestStatus::Disetujui)
        ->and($line->source->value)->toBe('CUSTOM')
        ->and($line->review_decision)->toBe('CUSTOM')
        ->and($line->qty_planned)->toBe(3.0)
        ->and($line->display_name)->toBe('Handle pintu aluminium 60cm (Hitam doff)')
        // What was asked stays visible next to what was approved.
        ->and($line->requested_snapshot['qty'])->toEqual(4)
        ->and($line->requested_snapshot['name'])->toBe('Handle pintu panjang')
        ->and(Notification::where('user_id', $this->tukang->id)->where('type', 'material_request_decided')->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'logistics.material_request_pm_approved')->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'logistics.material_request_approved')->exists())->toBeTrue();
});

test('a Tukang cannot request on a project where they have no task', function () {
    $other = Project::factory()->create();

    $this->actingAs($this->tukang)->post(route('projects.material-requests.store', $other), ['name' => 'Paku', 'qty' => 1])
        ->assertForbidden();
});

test('a Tukang cannot attach a vendor or photo link — Logistics completes the request', function () {
    $this->actingAs($this->tukang)->post(route('projects.material-requests.store', $this->project), [
        'name' => 'Paku', 'qty' => 1, 'vendor_id' => vendorId('Toko Besi'),
    ])->assertSessionHasErrors('vendor_id');
});

test('only the project\'s own PM decides a Tukang request, and a rejection needs a reason', function () {
    $line = app(MaterialRequestService::class)->submit($this->project, ['name' => 'Lem', 'qty' => 1], $this->tukang);

    $this->actingAs(requestUser('PM'))->post(route('project-materials.pmDecision', $line), ['decision' => 'approve'])->assertForbidden();
    $this->actingAs($this->pm)->post(route('project-materials.pmDecision', $line), ['decision' => 'reject'])
        ->assertSessionHasErrors(['reason' => 'Alasan penolakan wajib diisi.']);

    $this->actingAs($this->pm)->post(route('project-materials.pmDecision', $line), ['decision' => 'reject', 'reason' => 'Masih ada stok di lokasi'])
        ->assertSessionHasNoErrors();

    expect($line->fresh()->request_status)->toBe(MaterialRequestStatus::Ditolak)
        ->and($line->fresh()->reject_reason)->toBe('Masih ada stok di lokasi')
        ->and(Notification::where('user_id', $this->tukang->id)->where('type', 'material_request_decided')->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'logistics.material_request_pm_rejected')->exists())->toBeTrue();
});

test('a PM decision on a request that already reached Logistics is refused', function () {
    $line = submittedRequest($this->project, $this->pm);

    $this->actingAs($this->pm)->post(route('project-materials.pmDecision', $line), ['decision' => 'approve'])
        ->assertSessionHasErrors('decision');
});

// ── Jalur A: PM / Estimator → Logistik ───────────────────────────────────

test('PM and Estimator requests go straight to Logistics and need the full data', function (string $role) {
    $user = $role === 'PM' ? $this->pm : requestUser($role);

    $this->actingAs($user)->post(route('projects.material-requests.store', $this->project), ['name' => 'Kaca', 'qty' => 1])
        ->assertSessionHasErrors(['spec', 'unit_id', 'estimated_price', 'reason']);

    $this->actingAs($user)->post(route('projects.material-requests.store', $this->project), teamRequest())
        ->assertSessionHasNoErrors();

    $line = ProjectMaterial::sole();
    expect($line->request_status)->toBe(MaterialRequestStatus::Diajukan)
        ->and($line->request_channel)->toBe(ProjectMaterial::CHANNEL_TIM)
        ->and(Notification::where('user_id', $this->logistics->id)->where('type', 'material_request_submitted')->exists())->toBeTrue();
})->with(['PM', 'ESTIMATOR']);

test('another project\'s PM and Logistics cannot raise a request on this project', function (string $role) {
    $this->actingAs(requestUser($role))->post(route('projects.material-requests.store', $this->project), teamRequest())
        ->assertForbidden();
})->with(['PM', 'LOGISTICS', 'FINANCE']);

test('a line that is not approved yet cannot be bought or used', function () {
    $line = submittedRequest($this->project, $this->pm);

    $this->actingAs($this->pm)->post(route('project-materials.purchase', $line), [
        'qty' => 1, 'unit_price' => 1, 'purchase_date' => now()->toDateString(),
    ])->assertSessionHasErrors(['qty' => 'Baris material ini belum disetujui Logistik.']);
    $this->actingAs($this->pm)->post(route('project-materials.usage', $line), ['qty' => 1])
        ->assertSessionHasErrors('qty');
});

// ── Logistik: 4 keputusan ────────────────────────────────────────────────

test('Logistics can use an existing catalog item from the warehouse', function () {
    $line = submittedRequest($this->project, $this->pm);
    $kaca = Material::factory()->create(['name' => 'Kaca 8mm', 'unit_id' => unitId('lbr'), 'stock' => 10]);

    $this->actingAs($this->logistics)->post(route('logistics.material-requests.review', $line), [
        'decision' => 'PAKAI_KATALOG', 'material_id' => $kaca->id, 'source' => 'GUDANG', 'qty' => 2,
    ])->assertSessionHasNoErrors();

    $line->refresh();
    expect($line->source->value)->toBe('GUDANG')
        ->and($line->material_id)->toBe($kaca->id)
        ->and($line->unit_id)->toBe($kaca->unit_id)
        ->and($line->display_name)->toBe('Kaca 8mm');

    // It is now a normal GUDANG line: Logistics issues stock to exactly this line.
    app(ProjectMaterialService::class)->issue($line, ['qty' => 2], $this->logistics);
    expect($line->fresh()->qty_received)->toBe(2.0)->and($kaca->fresh()->stock)->toBe(8.0);
});

test('a request approved as an item the project already planned becomes its own line', function () {
    $kaca = Material::factory()->create(['unit_id' => unitId('lbr'), 'stock' => 10]);
    $planned = ProjectMaterial::factory()->create(['project_id' => $this->project->id, 'material_id' => $kaca->id, 'unit_id' => $kaca->unit_id]);
    $line = submittedRequest($this->project, $this->pm);

    app(MaterialRequestService::class)->review($line, ['decision' => 'PAKAI_KATALOG', 'material_id' => $kaca->id, 'source' => 'GUDANG', 'qty' => 1], $this->logistics);

    expect(ProjectMaterial::where('material_id', $kaca->id)->count())->toBe(2)
        ->and($planned->fresh()->qty_planned)->toBe(20.0);
});

test('Logistics can register a new catalog item and refuses an existing one written differently', function () {
    $line = submittedRequest($this->project, $this->pm);
    Material::factory()->create(['name' => 'Kaca Tempered 10mm', 'unit_id' => unitId('lbr'), 'material_category_id' => categoryId('KCA')]);
    $payload = [
        'decision' => 'DAFTAR_KATALOG', 'unit_id' => unitId('lbr'), 'material_category_id' => categoryId('KCA'),
        'warehouse_price' => 400_000, 'unit_price' => 430_000, 'qty' => 2, 'vendor_id' => vendorId('Kaca Jaya'),
    ];

    $this->actingAs($this->logistics)->post(route('logistics.material-requests.review', $line), [...$payload, 'name' => ' kaca tempered  10 MM'])
        ->assertSessionHasErrors('name');

    // A similar (not identical) item needs a reason before it's registered.
    $this->actingAs($this->logistics)->post(route('logistics.material-requests.review', $line), [...$payload, 'name' => 'Kaca Tempered 8mm'])
        ->assertSessionHasErrors('similar_reason');

    $this->actingAs($this->logistics)->post(route('logistics.material-requests.review', $line), [...$payload, 'name' => 'Kaca Tempered 8mm', 'similar_reason' => 'Ketebalan berbeda'])
        ->assertSessionHasNoErrors();

    $material = Material::where('name', 'Kaca Tempered 8mm')->sole();
    $line->refresh();
    expect((float) $material->cost_price)->toBe(400_000.0)
        ->and($line->source->value)->toBe('PEMBELIAN')
        ->and($line->material_id)->toBe($material->id)
        ->and((float) $line->unit_price)->toBe(430_000.0)
        ->and($line->review_decision)->toBe('DAFTAR_KATALOG')
        ->and(AuditLog::where('action', 'logistics.material_registered')->exists())->toBeTrue();
});

test('Logistics rejects a request with a reason', function () {
    $line = submittedRequest($this->project, $this->pm);

    $this->actingAs($this->logistics)->post(route('logistics.material-requests.review', $line), ['decision' => 'TOLAK'])
        ->assertSessionHasErrors('reject_reason');
    $this->actingAs($this->logistics)->post(route('logistics.material-requests.review', $line), ['decision' => 'TOLAK', 'reject_reason' => 'Pakai kaca stok'])
        ->assertSessionHasNoErrors();

    expect($line->fresh()->request_status)->toBe(MaterialRequestStatus::Ditolak)
        ->and(AuditLog::where('action', 'logistics.material_request_rejected')->exists())->toBeTrue()
        ->and(Notification::where('user_id', $this->pm->id)->where('type', 'material_request_decided')->exists())->toBeTrue();
});

test('only Logistics reviews requests', function (string $role) {
    $line = submittedRequest($this->project, $this->pm);

    $this->actingAs($role === 'PM' ? $this->pm : requestUser($role))
        ->post(route('logistics.material-requests.review', $line), ['decision' => 'TOLAK', 'reject_reason' => 'x'])
        ->assertForbidden();
})->with(['PM', 'CEO', 'ESTIMATOR']);

// ── Retur barang custom ke katalog baru ──────────────────────────────────

test('a custom leftover can be registered as a new catalog item with its warehouse price on return', function () {
    $line = submittedRequest($this->project, $this->pm);
    $requests = app(MaterialRequestService::class);
    $materials = app(ProjectMaterialService::class);
    $requests->review($line, ['decision' => 'CUSTOM', 'name' => 'Kaca potong 8mm', 'unit_id' => unitId('lbr'), 'qty' => 3, 'unit_price' => 450_000], $this->logistics);
    $materials->recordPurchase($line, ['qty' => 3, 'unit_price' => 450_000], $this->pm);
    $materials->recordUsage($line, ['qty' => 2], $this->pm);

    $this->actingAs($this->logistics)->post(route('project-materials.return', $line), [
        'qty' => 1,
        'movement_date' => now()->toDateString(),
        'new_material' => ['name' => 'Kaca 8mm sisa potong', 'material_category_id' => categoryId('KCA'), 'cost_price' => 300_000],
    ])->assertSessionHasNoErrors();

    $material = Material::where('name', 'Kaca 8mm sisa potong')->sole();
    expect($material->stock)->toBe(1.0)
        ->and($material->unit_id)->toBe(unitId('lbr'))
        ->and((float) $material->cost_price)->toBe(300_000.0)
        ->and($line->fresh()->leftover)->toBe(0.0);
});

// ── Blokir COMPLETED ─────────────────────────────────────────────────────

test('an undecided request keeps the project from completing', function (string $status) {
    Milestone::factory()->create(['project_id' => $this->project->id, 'status' => MilestoneStatus::Completed->value]);
    $line = $status === 'MENUNGGU_PM'
        ? app(MaterialRequestService::class)->submit($this->project, ['name' => 'Lem', 'qty' => 1], $this->tukang)
        : submittedRequest($this->project, $this->pm);

    expect(app(ProjectService::class)->completeIfFinished($this->project, notifyWhenBlocked: true))->toBeFalse()
        ->and(Notification::where('user_id', $this->pm->id)->where('type', 'project_material_leftover')->sole()->message)
        ->toContain('pengajuan barang yang belum diputuskan');

    // Rejecting the last open request lets it complete.
    if ($status === 'MENUNGGU_PM') {
        app(MaterialRequestService::class)->pmDecide($line, 'reject', 'Tidak perlu', $this->pm);
    } else {
        app(MaterialRequestService::class)->review($line, ['decision' => 'TOLAK', 'reject_reason' => 'Tidak perlu'], $this->logistics);
    }

    expect($this->project->fresh()->status)->toBe(ProjectStatus::Completed);
})->with(['MENUNGGU_PM', 'DIAJUKAN']);

// ── Pengingat 1 hari kerja ───────────────────────────────────────────────

test('requests waiting more than a working day remind Logistics, the PM and the CEO — once per day', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00', 'Asia/Jakarta')); // Senin
    submittedRequest($this->project, $this->pm);
    app(MaterialRequestService::class)->submit($this->project, ['name' => 'Lem', 'qty' => 1], $this->tukang);
    $service = app(MaterialRequestService::class);

    Carbon::setTestNow(Carbon::parse('2026-10-06 09:00', 'Asia/Jakarta')); // Selasa — less than a full day
    expect($service->sendReminders())->toBe(0);

    Carbon::setTestNow(Carbon::parse('2026-10-07 09:00', 'Asia/Jakarta')); // Rabu
    expect($service->sendReminders())->toBe(2)
        ->and($service->sendReminders())->toBe(0)
        ->and(Notification::where('user_id', $this->logistics->id)->where('type', 'material_request_reminder')->count())->toBe(1)
        ->and(Notification::where('user_id', $this->pm->id)->where('type', 'material_request_reminder')->count())->toBe(1)
        ->and(Notification::where('user_id', $this->ceo->id)->where('type', 'material_request_summary')->count())->toBe(1);

    Carbon::setTestNow(Carbon::parse('2026-10-08 09:00', 'Asia/Jakarta')); // Kamis — still waiting
    expect($service->sendReminders())->toBe(2)
        ->and(Notification::where('user_id', $this->ceo->id)->where('type', 'material_request_summary')->count())->toBe(2);

    Carbon::setTestNow();
});

test('the reminder skips Sunday when counting a working day', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-10 10:00', 'Asia/Jakarta')); // Sabtu
    submittedRequest($this->project, $this->pm);

    Carbon::setTestNow(Carbon::parse('2026-10-12 09:00', 'Asia/Jakarta')); // Senin
    expect(app(MaterialRequestService::class)->sendReminders())->toBe(0);

    Carbon::setTestNow(Carbon::parse('2026-10-13 09:00', 'Asia/Jakarta')); // Selasa
    expect(app(MaterialRequestService::class)->sendReminders())->toBe(1);

    Carbon::setTestNow();
});

// ── Halaman antrean ──────────────────────────────────────────────────────

test('the request queue is open to the involved roles and closed to the rest', function (string $role, int $status) {
    $this->actingAs(requestUser($role))->get(route('logistics.material-requests.index'))->assertStatus($status);
})->with([
    ['CEO', 200], ['PM', 200], ['LOGISTICS', 200], ['ESTIMATOR', 200], ['FIELD_STAFF', 200],
    ['FINANCE', 403], ['MARKETING', 403], ['QA', 403],
]);

test('each role sees its own slice of the queue; Logistics also gets similar items', function () {
    Material::factory()->create(['name' => 'Kaca Tempered 10mm']);
    submittedRequest($this->project, $this->pm);
    app(MaterialRequestService::class)->submit($this->project, ['name' => 'Lem', 'qty' => 1], $this->tukang);
    $otherProject = Project::factory()->create();
    submittedRequest($otherProject, requestUser('ESTIMATOR'), ['name' => 'Kaca cermin']);

    $this->actingAs($this->logistics)->get(route('logistics.material-requests.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('requests.data', 3)
            ->where('counts.DIAJUKAN', 2)
            ->where('counts.MENUNGGU_PM', 1)
            ->where('permissions.review', true)
            ->has('requests.data.0.similar_catalog', 1)
            ->where('requests.data.0.similar_requests.0.custom_name', 'Kaca cermin'));

    $this->actingAs($this->pm)->get(route('logistics.material-requests.index'))
        ->assertInertia(fn (Assert $page) => $page->has('requests.data', 2)->has('requests.data.0.similar_catalog', 0));

    $this->actingAs($this->tukang)->get(route('logistics.material-requests.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('requests.data', 1)
            ->where('isTukang', true)
            ->where('requestProjects.0.id', $this->project->id));
});

test('the Tukang task list offers the request form for projects where they have a task', function () {
    Project::factory()->create(); // not theirs

    $this->actingAs($this->tukang)->get(route('tasks.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('materialRequestProjects', 1)
            ->where('materialRequestProjects.0.id', $this->project->id));

    $this->actingAs($this->pm)->get(route('tasks.index'))
        ->assertInertia(fn (Assert $page) => $page->has('materialRequestProjects', 0));
});

test('the project material tab offers requesting to the project PM and Estimator, deciding to the PM', function () {
    $this->actingAs($this->pm)->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page
            ->where('materialPermissions.request', true)
            ->where('materialPermissions.pmDecide', true)
            ->where('materialPermissions.review', false));

    $this->actingAs(requestUser('PM'))->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page->where('materialPermissions.request', false)->where('materialPermissions.pmDecide', false));

    $this->actingAs($this->logistics)->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page->where('materialPermissions.request', false)->where('materialPermissions.review', true));
});
