<?php

use App\Enums\ProjectStatus;
use App\Models\AuditLog;
use App\Models\BudgetLine;
use App\Models\BudgetOverrunRequest;
use App\Models\BudgetRealization;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Models\Vendor;
use App\Services\BudgetRealizationService;
use App\Services\ProjectBudgetService;
use Database\Seeders\RoleSeeder;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 12 Sub 10 — realisation per item (decision #27) and the CEO's
 * approval of a realisation over the post budget (#28).
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->pm = realizationUser('PM');
    $this->ceo = realizationUser('CEO');
    $quotation = Quotation::factory()->approved()->create(['items_total' => 1_250_000, 'total_amount' => 1_250_000]);
    $this->project = Project::factory()->create([
        'lead_id' => $quotation->lead_id,
        'quotation_id' => $quotation->id,
        'pm_id' => $this->pm->id,
    ]);
    Invoice::create([
        'number' => 'INV-REAL-0001',
        'lead_id' => $this->project->lead_id,
        'project_id' => $this->project->id,
        'type' => 'DP',
        'amount' => 375_000,
        'due_date' => now()->toDateString(),
        'status' => 'TERVERIFIKASI',
        'issued_by' => $this->pm->id,
        'issued_at' => now(),
    ]);

    // Pos "Listrik" (Excel Kopi OZ): stop kontak 5 × 50.000 + LED strip 4,15 m × 241.000 ≈ 1.250.000.
    $stopKontak = QuotationItem::factory()->create(['quotation_id' => $quotation->id, 'description' => 'Stop Kontak', 'qty' => 5, 'unit_price' => 50_000, 'total_price' => 250_000]);
    $led = QuotationItem::factory()->create(['quotation_id' => $quotation->id, 'description' => 'LED Strip', 'qty' => 4.15, 'unit_price' => 240_964, 'total_price' => 1_000_000]);
    $budget = app(ProjectBudgetService::class);
    $this->post = $budget->createPost($this->project, 'Listrik', $this->pm);
    $budget->allocate($this->post, [$stopKontak->id, $led->id], $this->pm);
    $this->stopKontak = BudgetLine::where('quotation_item_id', $stopKontak->id)->sole();
    $this->led = BudgetLine::where('quotation_item_id', $led->id)->sole();
});

function realizationUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole(User::rolesFor($role));

    return $user;
}

function realize(object $test, BudgetLine $line, float $qty, float $unitCost, array $extra = [])
{
    return $test->actingAs($test->pm)->post(route('projects.budget.realizations.store', [$test->project, $line]), [
        'qty_actual' => $qty,
        'unit_cost' => $unitCost,
        ...$extra,
    ]);
}

// ── Recording (#27) ─────────────────────────────────────────────────────

test('the PM records real qty × harga modal with an optional vendor; Finance sees budget vs realisation per post', function () {
    $vendor = Vendor::factory()->create(['is_active' => true]);

    realize($this, $this->stopKontak, 5, 45_000, ['vendor_id' => $vendor->id, 'note' => 'Broco'])->assertSessionHasNoErrors();
    // LED strip: RAB 4,15 m, real 2,5 m — qty may differ from the RAB.
    realize($this, $this->led, 2.5, 120_000)->assertSessionHasNoErrors();

    $row = BudgetRealization::where('budget_line_id', $this->stopKontak->id)->sole();
    expect((float) $row->total_cost)->toBe(225_000.0)
        ->and($row->vendor_id)->toBe($vendor->id)
        ->and($row->recorded_by)->toBe($this->pm->id)
        ->and((float) BudgetRealization::where('budget_line_id', $this->led->id)->sole()->total_cost)->toBe(300_000.0);

    $this->actingAs(realizationUser('FINANCE'))->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page
            ->where('budget.posts.0.total', 1_250_000)
            ->where('budget.posts.0.realized', 525_000)
            ->where('budget.posts.0.difference', 725_000)
            ->where('budget.posts.0.margin', 58)
            ->where('budget.posts.0.lines.0.realized', 225_000)
            ->where('budget.posts.0.lines.0.realizations.0.vendor', $vendor->name)
            ->where('budget.summary.realizedTotal', 525_000));
});

test('a realisation pushing the post over its budget is refused with the amount', function () {
    realize($this, $this->led, 7.5, 180_000)
        ->assertSessionHasErrors(['overrun' => 'Melebihi anggaran pos Listrik sebesar Rp 100.000 — ajukan persetujuan CEO.']);

    expect(BudgetRealization::count())->toBe(0);
});

test('the overrun check counts the whole post, not just the item', function () {
    realize($this, $this->stopKontak, 5, 60_000)->assertSessionHasNoErrors(); // 300.000 > 250.000 budget of the item, post still fine.
    realize($this, $this->led, 4, 240_000)->assertSessionHasErrors('overrun'); // 300.000 + 960.000 > 1.250.000
    realize($this, $this->led, 4, 230_000)->assertSessionHasNoErrors(); // 300.000 + 920.000 ≤ 1.250.000
});

test('realisations are append-only: a correction is a cancelling row', function () {
    realize($this, $this->stopKontak, 5, 45_000)->assertSessionHasNoErrors();
    $row = BudgetRealization::sole();

    $this->actingAs($this->pm)->post(route('projects.budget.realizations.reverse', [$this->project, $row]), ['note' => 'Salah input'])
        ->assertSessionHasNoErrors();

    $reversal = BudgetRealization::where('reverses_id', $row->id)->sole();
    expect((float) $reversal->total_cost)->toBe(-225_000.0)
        ->and((float) BudgetRealization::sum('total_cost'))->toBe(0.0);

    $this->actingAs($this->pm)->post(route('projects.budget.realizations.reverse', [$this->project, $row]))->assertSessionHasErrors('realization');
    $this->actingAs($this->pm)->post(route('projects.budget.realizations.reverse', [$this->project, $reversal]))->assertSessionHasErrors('realization');

    expect(fn () => $row->update(['total_cost' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $row->delete())->toThrow(LogicException::class);
});

test('an item with realisations stays in its post', function () {
    realize($this, $this->stopKontak, 5, 45_000)->assertSessionHasNoErrors();
    $other = app(ProjectBudgetService::class)->createPost($this->project, 'Lain-lain', $this->pm);

    $this->actingAs($this->pm)->post(route('projects.budget.allocate', $this->project), [
        'budget_post_id' => $other->id,
        'item_ids' => [$this->stopKontak->quotation_item_id],
    ])->assertSessionHasErrors('item_ids');
    $this->actingAs($this->pm)->post(route('projects.budget.allocate', $this->project), ['item_ids' => [$this->stopKontak->quotation_item_id]])
        ->assertSessionHasErrors('item_ids');

    expect($this->stopKontak->fresh()->budget_post_id)->toBe($this->post->id);
});

// ── Overrun → CEO (#28) ─────────────────────────────────────────────────

test('an overrun goes to the CEO and is recorded once approved', function () {
    $this->actingAs($this->pm)->post(route('projects.budget.overruns.store', [$this->project, $this->led]), [
        'qty_actual' => 7.5,
        'unit_cost' => 180_000,
        'note' => 'LED + power supply',
        'reason' => 'Panjang riil 7,5 m, RAB 4,15 m.',
    ])->assertSessionHasNoErrors();

    $request = BudgetOverrunRequest::sole();
    expect($request->status)->toBe(BudgetOverrunRequest::STATUS_WAITING)
        ->and((float) $request->amount_over)->toBe(100_000.0)
        ->and(BudgetRealization::count())->toBe(0)
        ->and(Notification::where('user_id', $this->ceo->id)->where('type', 'budget_overrun_requested')->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'finance.budget_overrun_requested')->exists())->toBeTrue();

    $this->actingAs($this->ceo)->get(route('analytics.index'))
        ->assertInertia(fn (Assert $page) => $page->where('overrunRequests.0.id', $request->id)->where('overrunRequests.0.post', 'Listrik'));

    $this->actingAs($this->ceo)->post(route('projects.budget.overruns.decide', $request), ['decision' => 'approve'])
        ->assertSessionHasNoErrors();

    $row = BudgetRealization::sole();
    expect($request->fresh()->status)->toBe(BudgetOverrunRequest::STATUS_APPROVED)
        ->and($request->fresh()->decided_by)->toBe($this->ceo->id)
        ->and((float) $row->total_cost)->toBe(1_350_000.0)
        ->and($row->overrun_request_id)->toBe($request->id)
        ->and($row->recorded_by)->toBe($this->pm->id)
        ->and(Notification::where('user_id', $this->pm->id)->where('type', 'budget_overrun_approved')->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'finance.budget_overrun_approved')->exists())->toBeTrue();

    $this->actingAs($this->ceo)->post(route('projects.budget.overruns.decide', $request), ['decision' => 'reject', 'note' => 'x'])
        ->assertSessionHasErrors('decision');
});

test('a rejected overrun is never recorded and needs a note', function () {
    $request = app(BudgetRealizationService::class)->requestOverrun($this->led, [
        'qty_actual' => 7.5, 'unit_cost' => 180_000, 'reason' => 'Panjang riil lebih.',
    ], $this->pm);

    $this->actingAs($this->ceo)->post(route('projects.budget.overruns.decide', $request), ['decision' => 'reject'])
        ->assertSessionHasErrors('note');
    $this->actingAs($this->ceo)->post(route('projects.budget.overruns.decide', $request), ['decision' => 'reject', 'note' => 'Pakai sisa LED gudang.'])
        ->assertSessionHasNoErrors();

    expect($request->fresh()->status)->toBe(BudgetOverrunRequest::STATUS_REJECTED)
        ->and($request->fresh()->decision_note)->toBe('Pakai sisa LED gudang.')
        ->and(BudgetRealization::count())->toBe(0)
        ->and(Notification::where('user_id', $this->pm->id)->where('type', 'budget_overrun_rejected')->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'finance.budget_overrun_rejected')->exists())->toBeTrue();
});

test('an overrun request must really overrun, and one waits per post at a time', function () {
    $service = app(BudgetRealizationService::class);

    expect(fn () => $service->requestOverrun($this->stopKontak, ['qty_actual' => 1, 'unit_cost' => 10_000, 'reason' => 'Coba saja'], $this->pm))
        ->toThrow(ValidationException::class);

    $service->requestOverrun($this->led, ['qty_actual' => 7.5, 'unit_cost' => 180_000, 'reason' => 'Panjang riil lebih.'], $this->pm);

    $this->actingAs($this->pm)->post(route('projects.budget.overruns.store', [$this->project, $this->stopKontak]), [
        'qty_actual' => 50, 'unit_cost' => 50_000, 'reason' => 'Tambah titik stop kontak.',
    ])->assertSessionHasErrors(['reason' => 'Masih ada pengajuan overrun pos Listrik yang menunggu keputusan CEO.']);

    expect(BudgetOverrunRequest::count())->toBe(1);
});

test('the overrun request needs a reason; a plain realisation may not carry one', function () {
    $this->actingAs($this->pm)->post(route('projects.budget.overruns.store', [$this->project, $this->led]), ['qty_actual' => 7.5, 'unit_cost' => 180_000])
        ->assertSessionHasErrors('reason');
    realize($this, $this->stopKontak, 1, 1_000, ['reason' => 'x'])->assertSessionHasErrors('reason');
});

// ── RBAC ────────────────────────────────────────────────────────────────

test('only the PM of the project records realisations or asks for an overrun', function (string $role) {
    $user = realizationUser($role);

    $this->actingAs($user)->post(route('projects.budget.realizations.store', [$this->project, $this->stopKontak]), ['qty_actual' => 1, 'unit_cost' => 1_000])
        ->assertForbidden();
    $this->actingAs($user)->post(route('projects.budget.overruns.store', [$this->project, $this->led]), ['qty_actual' => 9, 'unit_cost' => 200_000, 'reason' => 'Lebih panjang.'])
        ->assertForbidden();

    expect(BudgetRealization::count())->toBe(0)->and(BudgetOverrunRequest::count())->toBe(0);
})->with(['another PM' => ['PM'], 'ASISTEN_PM', 'MARKETING', 'CEO', 'FINANCE']);

test('only the CEO decides an overrun', function (string $role) {
    $request = app(BudgetRealizationService::class)->requestOverrun($this->led, [
        'qty_actual' => 7.5, 'unit_cost' => 180_000, 'reason' => 'Panjang riil lebih.',
    ], $this->pm);

    $this->actingAs(realizationUser($role))->post(route('projects.budget.overruns.decide', $request), ['decision' => 'approve'])->assertForbidden();

    expect($request->fresh()->status)->toBe(BudgetOverrunRequest::STATUS_WAITING);
})->with(['PM', 'FINANCE', 'ASISTEN_PM', 'MARKETING']);

test('a line of another project is a 404', function () {
    $other = Project::factory()->create(['pm_id' => $this->pm->id]);

    $this->actingAs($this->pm)->post(route('projects.budget.realizations.store', [$other, $this->stopKontak]), ['qty_actual' => 1, 'unit_cost' => 1_000])
        ->assertNotFound();
});

test('no realisation on a closed project, and Marketing / Asisten PM never get the figures', function () {
    $this->project->update(['status' => ProjectStatus::Completed->value]);

    realize($this, $this->stopKontak, 1, 1_000)->assertSessionHasErrors('post');

    foreach (['MARKETING', 'ASISTEN_PM'] as $role) {
        $user = realizationUser($role);
        $this->project->update(['assistant_pm_id' => $role === 'ASISTEN_PM' ? $user->id : null]);
        $this->actingAs($user)->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('budget', null)->where('budgetVendors', []));
    }
});

test('the CEO can decide from the project tab, the PM gets the vendor list', function () {
    $this->actingAs($this->ceo)->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page->where('canDecideOverrun', true)->where('canManageBudget', false)->where('budgetVendors', []));
    $this->actingAs($this->pm)->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page->where('canDecideOverrun', false)->where('canManageBudget', true)->has('budgetVendors'));
});
