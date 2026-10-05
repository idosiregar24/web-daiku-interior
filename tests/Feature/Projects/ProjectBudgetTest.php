<?php

use App\Enums\InvoiceStatus;
use App\Enums\ProjectStatus;
use App\Models\BudgetAllocationLog;
use App\Models\BudgetLine;
use App\Models\BudgetPost;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Services\ProjectBudgetService;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 12 Sub 9 — "Alokasi Dana Proyek" (decisions #23–#26): after the
 * first verified payment the project's PM groups the RAB Fix items into
 * freely named posts; warnings, not blocks; every change logged.
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->pm = budgetUser('PM');
    // RAB Fix: 10 jt + 6 jt + 4 jt = 20 jt, discount 1 jt → 19 jt.
    $this->quotation = Quotation::factory()->approved()->create([
        'items_total' => 20_000_000,
        'discount_amount' => 1_000_000,
        'rounded_total' => null,
        'total_amount' => 19_000_000,
    ]);
    $this->items = collect([
        ['Kitchen Set Custom', 10_000_000],
        ['Instalasi Listrik', 6_000_000],
        ['Mural Dinding', 4_000_000],
    ])->map(fn (array $row, int $i) => QuotationItem::factory()->create([
        'quotation_id' => $this->quotation->id,
        'description' => $row[0],
        'qty' => 1,
        'unit_price' => $row[1],
        'total_price' => $row[1],
        'sort_order' => $i,
    ]));
    $this->project = Project::factory()->create([
        'lead_id' => $this->quotation->lead_id,
        'quotation_id' => $this->quotation->id,
        'pm_id' => $this->pm->id,
        'contract_value' => 19_000_000,
    ]);
});

function budgetUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole(User::rolesFor($role));

    return $user;
}

/** Decision #23 — the project's first payment verified by Finance. */
function verifiedPayment(Project $project, InvoiceStatus $status = InvoiceStatus::Terverifikasi): Invoice
{
    return Invoice::create([
        'number' => 'INV-TEST-'.fake()->unique()->numberBetween(1000, 9999),
        'lead_id' => $project->lead_id,
        'project_id' => $project->id,
        'type' => 'DP',
        'amount' => 5_700_000,
        'due_date' => now()->toDateString(),
        'status' => $status->value,
        'issued_by' => User::factory()->create()->id,
        'issued_at' => now(),
    ]);
}

function budgetPost(object $test, string $name = 'Interior'): BudgetPost
{
    return app(ProjectBudgetService::class)->createPost($test->project, $name, $test->pm);
}

// ── Opening (#23) ───────────────────────────────────────────────────────

test('allocation stays closed until a payment of the project is verified', function () {
    verifiedPayment($this->project, InvoiceStatus::MenungguVerifikasi);

    $this->actingAs($this->pm)->post(route('projects.budget.posts.store', $this->project), ['name' => 'Interior'])
        ->assertSessionHasErrors(['post' => 'Alokasi dibuka setelah pembayaran pertama diverifikasi Finance.']);

    $this->actingAs($this->pm)->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page->where('budget.isOpen', false)->where('budget.hasRab', true));

    verifiedPayment($this->project);

    $this->actingAs($this->pm)->post(route('projects.budget.posts.store', $this->project), ['name' => 'Interior'])
        ->assertSessionHasNoErrors();
    expect(BudgetPost::sole()->name)->toBe('Interior');
});

// ── RBAC (PM of the project writes; CEO / Finance / PMs read) ───────────

test('only the PM of the project changes the allocation', function (string $role) {
    verifiedPayment($this->project);
    $post = budgetPost($this);
    $user = budgetUser($role);

    $this->actingAs($user)->post(route('projects.budget.posts.store', $this->project), ['name' => 'Listrik'])->assertForbidden();
    $this->actingAs($user)->put(route('projects.budget.posts.update', [$this->project, $post]), ['name' => 'X'])->assertForbidden();
    $this->actingAs($user)->delete(route('projects.budget.posts.destroy', [$this->project, $post]))->assertForbidden();
    $this->actingAs($user)->post(route('projects.budget.allocate', $this->project), [
        'budget_post_id' => $post->id,
        'item_ids' => [$this->items[0]->id],
    ])->assertForbidden();

    expect(BudgetPost::count())->toBe(1)->and(BudgetLine::count())->toBe(0);
})->with(['another PM' => ['PM'], 'ASISTEN_PM', 'MARKETING', 'CEO', 'FINANCE', 'ESTIMATOR']);

test('CEO, Finance and PMs read the allocation; Marketing and the Asisten PM never get it', function (string $role, bool $sees) {
    verifiedPayment($this->project);
    $user = budgetUser($role);
    // An Asisten PM opens only the project it is assigned to (Sub 11) — and still gets no allocation.
    $this->project->update(['assistant_pm_id' => $role === 'ASISTEN_PM' ? $user->id : null]);

    $this->actingAs($user)->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $sees
            ? $page->has('budget.posts')->where('canManageBudget', false)
            : $page->where('budget', null)->where('canManageBudget', false));
})->with([['CEO', true], ['FINANCE', true], ['PM', true], ['MARKETING', false], ['ASISTEN_PM', false]]);

test('the project PM gets the manage flag, not on a closed project', function () {
    verifiedPayment($this->project);

    $this->actingAs($this->pm)->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page->where('canManageBudget', true));

    $this->project->update(['status' => ProjectStatus::Cancelled->value]);

    $this->actingAs($this->pm)->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page->where('canManageBudget', false));
    expect(fn () => budgetPost($this, 'Listrik'))->toThrow(ValidationException::class);
});

// ── Posts & items (#24–#25) ─────────────────────────────────────────────

test('the PM allocates items to posts; an item sits in one post, moving it takes it out of the other', function () {
    verifiedPayment($this->project);
    $interior = budgetPost($this, 'Interior');
    $listrik = budgetPost($this, 'Listrik');

    $this->actingAs($this->pm)->post(route('projects.budget.allocate', $this->project), [
        'budget_post_id' => $interior->id,
        'item_ids' => [$this->items[0]->id, $this->items[1]->id],
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->pm)->post(route('projects.budget.allocate', $this->project), [
        'budget_post_id' => $listrik->id,
        'item_ids' => [$this->items[1]->id],
    ])->assertSessionHasNoErrors();

    expect($interior->lines()->pluck('description')->all())->toBe(['Kitchen Set Custom'])
        ->and($listrik->lines()->pluck('description')->all())->toBe(['Instalasi Listrik'])
        ->and(BudgetLine::where('quotation_item_id', $this->items[1]->id)->count())->toBe(1)
        ->and((float) $listrik->lines()->sole()->sell_price)->toBe(6_000_000.0);

    // The database refuses a second line for the same item, too.
    expect(fn () => BudgetLine::create([
        'budget_post_id' => $interior->id,
        'quotation_item_id' => $this->items[1]->id,
        'description' => 'x',
        'qty' => 1,
        'unit_price' => 1,
        'sell_price' => 1,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('items go back to "belum dialokasikan" when taken out of their post', function () {
    verifiedPayment($this->project);
    $service = app(ProjectBudgetService::class);
    $post = budgetPost($this);
    $service->allocate($post, [$this->items[0]->id, $this->items[2]->id], $this->pm);

    $this->actingAs($this->pm)->post(route('projects.budget.allocate', $this->project), ['item_ids' => [$this->items[2]->id]])
        ->assertSessionHasNoErrors();

    $overview = $service->overview($this->project->fresh());
    expect(collect($overview['unallocatedItems'])->pluck('description')->all())->toBe(['Instalasi Listrik', 'Mural Dinding'])
        ->and($overview['summary']['unallocatedTotal'])->toBe(10_000_000.0)
        ->and($overview['summary']['postsTotal'])->toBe(10_000_000.0);
});

test('only items of this project RAB, only posts of this project', function () {
    verifiedPayment($this->project);
    $post = budgetPost($this);
    $foreignItem = QuotationItem::factory()->create();
    $otherProject = Project::factory()->create();
    $otherPost = BudgetPost::create(['project_id' => $otherProject->id, 'name' => 'Lain', 'created_by' => $this->pm->id]);

    $this->actingAs($this->pm)->post(route('projects.budget.allocate', $this->project), [
        'budget_post_id' => $post->id,
        'item_ids' => [$foreignItem->id],
    ])->assertSessionHasErrors('item_ids');

    $this->actingAs($this->pm)->post(route('projects.budget.allocate', $this->project), [
        'budget_post_id' => $otherPost->id,
        'item_ids' => [$this->items[0]->id],
    ])->assertNotFound();

    $this->actingAs($this->pm)->put(route('projects.budget.posts.update', [$this->project, $otherPost]), ['name' => 'X'])->assertNotFound();

    expect(BudgetLine::count())->toBe(0);
});

test('post names are free but unique per project; only an empty post can be removed', function () {
    verifiedPayment($this->project);
    $post = budgetPost($this, 'Listrik');

    $this->actingAs($this->pm)->post(route('projects.budget.posts.store', $this->project), ['name' => '  listrik '])
        ->assertSessionHasErrors(['name' => 'Pos "listrik" sudah ada.']);

    app(ProjectBudgetService::class)->allocate($post, [$this->items[1]->id], $this->pm);

    $this->actingAs($this->pm)->delete(route('projects.budget.posts.destroy', [$this->project, $post]))
        ->assertSessionHasErrors(['post' => 'Pos "Listrik" masih berisi item — pindahkan dulu itemnya.']);

    app(ProjectBudgetService::class)->unallocate($this->project, [$this->items[1]->id], $this->pm);
    $this->actingAs($this->pm)->delete(route('projects.budget.posts.destroy', [$this->project, $post]))->assertSessionHasNoErrors();

    expect(BudgetPost::count())->toBe(0);
});

test('posts can be renamed and reordered', function () {
    verifiedPayment($this->project);
    $a = budgetPost($this, 'Interior');
    $b = budgetPost($this, 'Mural');

    $this->actingAs($this->pm)->put(route('projects.budget.posts.update', [$this->project, $a]), ['name' => 'Interior & Furniture'])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->pm)->put(route('projects.budget.posts.reorder', $this->project), ['post_ids' => [$b->id, $a->id]])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->pm)->put(route('projects.budget.posts.reorder', $this->project), ['post_ids' => [$b->id]])
        ->assertSessionHasErrors('post_ids');

    expect($this->project->budgetPosts()->pluck('name')->all())->toBe(['Mural', 'Interior & Furniture']);
});

// ── Summary: discount & warning (#24, #26) ──────────────────────────────

test('the discount is only a deduction in the summary; posts above the RAB total warn without blocking', function () {
    verifiedPayment($this->project);
    $service = app(ProjectBudgetService::class);
    $post = budgetPost($this);

    $service->allocate($post, $this->items->take(2)->pluck('id')->all(), $this->pm);
    $summary = $service->overview($this->project->fresh())['summary'];

    expect($summary)->toMatchArray([
        'itemsTotal' => 20_000_000.0,
        'discount' => 1_000_000.0,
        'rounding' => 0.0,
        'rabTotal' => 19_000_000.0,
        'postsTotal' => 16_000_000.0,
        'unallocatedTotal' => 4_000_000.0,
        'overRab' => false,
    ]);

    // Every item allocated: 20 jt in posts > 19 jt RAB — a warning, the save went through.
    $service->allocate($post, [$this->items[2]->id], $this->pm);
    $summary = $service->overview($this->project->fresh())['summary'];

    expect($summary['postsTotal'])->toBe(20_000_000.0)
        ->and($summary['overRab'])->toBeTrue()
        ->and(BudgetLine::count())->toBe(3);
});

test('rounding shows what the rounded total adds after the discount', function () {
    verifiedPayment($this->project);
    $this->quotation->update(['rounded_total' => 18_950_000, 'total_amount' => 18_950_000]);

    expect(app(ProjectBudgetService::class)->overview($this->project->fresh())['summary']['rounding'])->toBe(-50_000.0);
});

// ── History (#25) ───────────────────────────────────────────────────────

test('every change is logged with before / after, and the log is append-only', function () {
    verifiedPayment($this->project);
    $service = app(ProjectBudgetService::class);
    $interior = budgetPost($this, 'Interior');
    $listrik = budgetPost($this, 'Listrik');
    $service->allocate($interior, [$this->items[1]->id], $this->pm);
    $service->allocate($listrik, [$this->items[1]->id], $this->pm);
    $service->renamePost($listrik, 'Elektrikal', $this->pm);
    $service->unallocate($this->project, [$this->items[1]->id], $this->pm);

    $logs = BudgetAllocationLog::orderBy('id')->get();
    expect($logs->pluck('action')->all())->toBe(['post_created', 'post_created', 'items_allocated', 'items_allocated', 'post_renamed', 'items_unallocated'])
        ->and($logs->every(fn ($log) => $log->user_id === $this->pm->id))->toBeTrue()
        ->and($logs[3]->before)->toBe(['items' => [['item' => 'Instalasi Listrik', 'post' => 'Interior']]])
        ->and($logs[3]->after)->toBe(['post' => 'Listrik', 'items' => ['Instalasi Listrik']])
        ->and($logs[4]->before)->toBe(['post' => 'Listrik'])
        ->and($logs[4]->after)->toBe(['post' => 'Elektrikal']);

    expect(fn () => $logs[0]->update(['action' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $logs[0]->delete())->toThrow(LogicException::class);

    $this->actingAs($this->pm)->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page->has('budget.logs', 6)->where('budget.logs.0.action', 'items_unallocated'));
});

test('a project without a RAB Fix has nothing to allocate', function () {
    $legacy = Project::factory()->create(['pm_id' => $this->pm->id, 'quotation_id' => null]);
    verifiedPayment($legacy);

    $this->actingAs($this->pm)->get(route('projects.show', $legacy))
        ->assertInertia(fn (Assert $page) => $page->where('budget.hasRab', false)->where('budget.unallocatedItems', []));
});
