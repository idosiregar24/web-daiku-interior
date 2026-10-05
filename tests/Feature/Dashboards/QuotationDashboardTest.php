<?php

use App\Enums\QuotationStatus;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\QuotationApproval;
use App\Models\User;
use App\Services\DivisionDashboardService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo(Carbon::parse('2026-09-30 10:00:00'));
});

function quotationDashboardUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** An approval-table row at a fixed moment (the table is append-only; created_at is its only clock). */
function quotationDecision(Quotation $quotation, string $role, string $status, string $at, ?string $note = null): QuotationApproval
{
    $approval = (new QuotationApproval)->forceFill([
        'quotation_id' => $quotation->id,
        'approver_id' => User::factory()->create()->id,
        'approver_role' => $role,
        'status' => $status,
        'note' => $note,
        'created_at' => Carbon::parse($at),
    ]);
    $approval->save();

    return $approval;
}

// ── RBAC: CEO + Estimator only ───────────────────────────────────────────

test('CEO and Estimator open the quotation dashboard', function (string $role) {
    $this->actingAs(quotationDashboardUser($role))->get(route('quotations.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Quotation/Dashboard')
            ->has('statusCounts', count(QuotationStatus::cases()))
            ->has('todo.rows')
            ->has('waitingCeo.rows')
            ->has('waitingPm.rows')
            ->has('monthly', 6)
            ->has('turnaround'));
})->with(['CEO', 'ESTIMATOR']);

test('every other role is refused the quotation dashboard', function (string $role) {
    $this->actingAs(quotationDashboardUser($role))->get(route('quotations.dashboard'))->assertForbidden();
})->with(['MARKETING', 'DESIGNER', 'PM', 'QA', 'FINANCE', 'LOGISTICS', 'FIELD_STAFF']);

// ── Queues & counts ──────────────────────────────────────────────────────

test('queues list the oldest first and a returned draft shows why it came back', function () {
    $rejected = Quotation::factory()->create(['total_amount' => 80_000_000, 'version' => 2, 'created_at' => '2026-09-20 09:00:00']);
    quotationDecision($rejected, 'PM', 'APPROVED', '2026-09-22 09:00:00');
    quotationDecision($rejected, 'CLIENT', 'REJECTED', '2026-09-25 09:00:00', 'Minta material lebih murah');
    $fresh = Quotation::factory()->create(['created_at' => '2026-09-29 09:00:00']);
    $submittedLater = Quotation::factory()->create(['status' => QuotationStatus::Submitted->value, 'updated_at' => '2026-09-29 08:00:00']);
    $submittedEarlier = Quotation::factory()->create(['status' => QuotationStatus::Submitted->value, 'updated_at' => '2026-09-26 08:00:00']);
    $waitingCeo = Quotation::factory()->create(['status' => QuotationStatus::WaitingCeo->value]);
    $approved = Quotation::factory()->create(['status' => QuotationStatus::ApprovedInternal->value]);
    Quotation::factory()->sentToClient()->create();

    $this->actingAs(quotationDashboardUser('ESTIMATOR'))->get(route('quotations.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('statusCounts.DRAFT', 2)
            ->where('statusCounts.SUBMITTED', 2)
            ->where('statusCounts.WAITING_CEO', 1)
            ->where('statusCounts.APPROVED_INTERNAL', 1)
            ->where('statusCounts.SENT_TO_CLIENT', 1)
            ->where('statusCounts.CLIENT_APPROVED', 0)
            ->where('todo.total', 2)
            ->where('todo.rows.0.id', $rejected->id)
            ->where('todo.rows.0.daysWaiting', 10)
            ->where('todo.rows.0.version', 2)
            ->where('todo.rows.0.lastRejection.role', 'CLIENT')
            ->where('todo.rows.0.lastRejection.note', 'Minta material lebih murah')
            ->where('todo.rows.1.id', $fresh->id)
            ->where('todo.rows.1.lastRejection', null)
            // Sprint 12: SUBMITTED waits for the PM review, then WAITING_CEO for the CEO.
            ->where('waitingPm.rows.0.id', $submittedEarlier->id)
            ->where('waitingPm.rows.0.daysWaiting', 4)
            ->where('waitingPm.rows.1.id', $submittedLater->id)
            ->where('waitingCeo.total', 1)
            ->where('waitingCeo.rows.0.id', $waitingCeo->id)
            ->where('readyToSend.total', 1)
            ->where('readyToSend.rows.0.id', $approved->id));
});

test('a draft whose latest decision was an approval shows no stale rejection note', function () {
    $quotation = Quotation::factory()->create();
    quotationDecision($quotation, 'CEO', 'REJECTED', '2026-09-20 09:00:00', 'Lama');
    quotationDecision($quotation, 'CEO', 'APPROVED', '2026-09-22 09:00:00');

    $row = app(DivisionDashboardService::class)->quotationQueue(QuotationStatus::Draft)['rows']->first();

    expect($row['lastRejection'])->toBeNull();
});

// ── RAB value per month & turnaround ─────────────────────────────────────

test('RAB value is bucketed by the last send month and the deal month', function () {
    // Sprint 12: Marketing's "Kirim ke Client" stamps first_sent_at / sent_at.
    $sentTwice = Quotation::factory()->sentToClient()->create(['total_amount' => 150_000_000, 'first_sent_at' => '2026-08-10 09:00:00', 'sent_at' => '2026-09-05 09:00:00']);
    quotationDecision($sentTwice, 'CLIENT', 'REJECTED', '2026-08-20 09:00:00', 'Revisi');

    $deal = Quotation::factory()->approved()->create(['total_amount' => 90_000_000, 'first_sent_at' => '2026-08-01 09:00:00', 'sent_at' => '2026-08-01 09:00:00']);
    Project::factory()->create(['lead_id' => $deal->lead_id, 'created_at' => '2026-09-12 09:00:00']);

    $months = app(DivisionDashboardService::class)->quotationMonthlyValue()->keyBy('month');

    expect($months)->toHaveCount(6)
        ->and($months['2026-09'])->toMatchArray(['sent' => 150_000_000.0, 'sentCount' => 1, 'deal' => 90_000_000.0, 'dealCount' => 1])
        ->and($months['2026-08'])->toMatchArray(['sent' => 90_000_000.0, 'sentCount' => 1, 'deal' => 0.0]);
});

test('turnaround runs from draft creation to the first send and counts rejections before it', function () {
    $quick = Quotation::factory()->sentToClient()->create(['created_at' => '2026-09-20 09:00:00', 'first_sent_at' => '2026-09-22 09:00:00']); // 2 days
    quotationDecision($quick, 'PM', 'APPROVED', '2026-09-21 09:00:00');

    $revised = Quotation::factory()->sentToClient()->create(['created_at' => '2026-09-01 09:00:00', 'first_sent_at' => '2026-09-07 09:00:00']); // 6 days, 1 return
    quotationDecision($revised, 'PM', 'REJECTED', '2026-09-03 09:00:00', 'Terlalu mahal');
    quotationDecision($revised, 'PM', 'APPROVED', '2026-09-05 09:00:00');
    quotationDecision($revised, 'CLIENT', 'REJECTED', '2026-09-10 09:00:00', 'Setelah terkirim'); // after the first send

    // First sent before the 6-month window — ignored.
    $old = Quotation::factory()->sentToClient()->create(['created_at' => '2026-01-01 09:00:00', 'first_sent_at' => '2026-02-20 09:00:00']);

    expect(app(DivisionDashboardService::class)->quotationTurnaround())->toBe([
        'avgDays' => 4.0,
        'count' => 2,
        'avgRejections' => 0.5,
    ]);
});
