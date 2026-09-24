<?php

use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\FamilyGatheringFund;
use App\Models\Milestone;
use App\Models\Penalty;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\Task;
use App\Models\Termin;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\FamilyGatheringFundService;
use App\Services\MilestoneService;
use App\Services\PenaltyService;
use App\Services\QaFormService;
use App\Services\QuotationService;
use App\Services\TerminService;
use App\Services\UserService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function auditUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

// ── PRD §9.4: sensitive actions are recorded ─────────────────────────────

test('a quotation approval is audited with actor and before/after', function () {
    $ceo = auditUser('CEO');
    $quotation = Quotation::factory()->create(['status' => 'SUBMITTED']);

    app(QuotationService::class)->ceoDecision($quotation, 'approve', $ceo);

    $log = AuditLog::sole();
    expect($log->action)->toBe('quotation.ceo_approved')
        ->and($log->user_id)->toBe($ceo->id)
        ->and($log->model_type)->toBe('Quotation')
        ->and($log->model_id)->toBe($quotation->id)
        ->and($log->old_values)->toBe(['status' => 'SUBMITTED'])
        ->and($log->new_values['status'])->toBe('CEO_REVIEW');
});

test('a QA decision is audited', function () {
    $milestone = Milestone::factory()->create(['status' => 'IN_PROGRESS']);
    app(MilestoneService::class)->markDone($milestone);
    $qaForm = $milestone->qaForm()->first();

    app(QaFormService::class)->review($qaForm, 'reject', $qaForm->checklist_data, 'Cat mengelupas', auditUser('QA'));

    $log = AuditLog::where('action', 'qa.rejected')->sole();
    expect($log->old_values['status'])->toBe('PENDING')
        ->and($log->new_values['rejection_count'])->toBe(1)
        ->and($log->new_values['notes'])->toBe('Cat mengelupas');
});

test('finance changes are audited over HTTP, with the client IP', function () {
    $finance = auditUser('FINANCE');
    $bank = BankAccount::factory()->create();

    $this->actingAs($finance)->post(route('finance.transactions.store'), [
        'bank_account_id' => $bank->id,
        'type' => 'PENGELUARAN',
        'kategori' => 'OPERASIONAL',
        'amount' => 250000,
        'description' => 'Sewa scaffolding',
        'date' => now()->toDateString(),
    ])->assertSessionHasNoErrors();

    $log = AuditLog::sole();
    expect($log->action)->toBe('finance.transaction_created')
        ->and($log->ip_address)->toBe('127.0.0.1')
        ->and((float) $log->new_values['amount'])->toBe(250000.0);
});

test('termin payment and fund usage are audited', function () {
    $finance = auditUser('FINANCE');
    app(TerminService::class)->markPaid(Termin::factory()->create(), $finance);
    FamilyGatheringFund::factory()->create(['type' => 'INCOME', 'amount' => 50000]);
    app(FamilyGatheringFundService::class)->recordExpense(['amount' => 20000, 'description' => 'Konsumsi'], $finance);

    expect(AuditLog::pluck('action')->all())->toContain('finance.termin_paid', 'finance.family_fund_expense');
});

test('a penalty issued by the scheduled job is audited as the system', function () {
    $staff = auditUser('FIELD_STAFF');
    Task::factory()->create(['assignee_id' => $staff->id, 'status' => 'ONPROGRESS']);

    app(PenaltyService::class)->runDailyCheck();

    $log = AuditLog::where('action', 'penalty.issued')->sole();
    expect($log->user_id)->toBeNull()
        ->and($log->model_id)->toBe(Penalty::sole()->id);
});

test('role changes are audited, the password never is', function () {
    $user = auditUser('MARKETING');

    app(UserService::class)->update($user, [
        'name' => $user->name,
        'email' => $user->email,
        'role' => 'PM',
        'password' => 'rahasia-baru-123',
    ]);

    $log = AuditLog::where('action', 'user.updated')->sole();
    expect($log->old_values)->toBe(['role' => 'MARKETING'])
        ->and($log->new_values)->toBe(['role' => 'PM', 'password_reset' => true])
        ->and(json_encode($log->new_values))->not->toContain('rahasia');
});

test('an audit row is only written when the audited action commits', function () {
    $ceo = auditUser('CEO');
    $quotation = Quotation::factory()->create(['status' => 'DRAFT']); // not reviewable

    expect(fn () => app(QuotationService::class)->ceoDecision($quotation, 'approve', $ceo))->toThrow(Exception::class);
    expect(AuditLog::count())->toBe(0);
});

// ── PRD §9.4: "tidak bisa dihapus oleh siapapun (termasuk CEO)" ───────────

test('audit rows cannot be updated or deleted, even through Eloquent', function () {
    $log = app(AuditLogService::class)->record('test.event', Project::factory()->create(), null, ['a' => 1], auditUser('CEO'));

    expect(fn () => $log->update(['action' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class)
        ->and(AuditLog::sole()->action)->toBe('test.event');
});

test('no route anywhere can modify or delete audit rows', function () {
    $auditRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'audit'))
        ->flatMap(fn ($route) => $route->methods());

    expect($auditRoutes->intersect(['POST', 'PUT', 'PATCH', 'DELETE'])->all())->toBe([]);
});

test('only the CEO can read the audit trail', function (string $role, int $status) {
    $this->actingAs(auditUser($role))->get(route('audit-logs.index'))->assertStatus($status);
})->with([
    ['CEO', 200], ['FINANCE', 403], ['PM', 403], ['QA', 403], ['MARKETING', 403], ['FIELD_STAFF', 403],
]);

test('the audit trail filters by area', function () {
    $ceo = auditUser('CEO');
    $project = Project::factory()->create();
    $audit = app(AuditLogService::class);
    $audit->record('finance.termin_paid', $project, null, [], $ceo);
    $audit->record('qa.approved', $project, null, [], $ceo);

    $this->actingAs($ceo)->get(route('audit-logs.index', ['area' => 'finance']))
        ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('logs.data.0.action', 'finance.termin_paid'));
});
