<?php

use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\QuotationApproval;
use App\Models\User;
use App\Services\Kpi\KpiMetricRegistry;
use App\Services\QuotationService;
use Database\Seeders\Demo\KpiDemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

/*
 * Sprint 12 Sub 13 — AUTO KPI metrics from the per-item RAB review
 * (decision #9): the Estimator's first-pass rate and returned count, and
 * the RAB a PM / Asisten PM approved that was turned down further on —
 * since Sprint 23 (K8, no CEO approval) by the client; legacy CEO returns
 * still count.
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->estimator = kpiReviewUser('ESTIMATOR');
    $this->pm = kpiReviewUser('PM');
    $this->marketing = kpiReviewUser('MARKETING');
    $this->registry = app(KpiMetricRegistry::class);
    $this->october = [Carbon::parse('2026-10-01'), Carbon::parse('2026-11-01')];
    $this->september = [Carbon::parse('2026-09-01'), Carbon::parse('2026-10-01')];
});

afterEach(fn () => Carbon::setTestNow());

function kpiReviewUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * The Estimator's RAB Proyek in October: v1 PM returns 1 of 4 items;
 * v2 PM approves, the client rejects it; v3 PM approves (all ✔ carried),
 * the client approves it on the link.
 */
function reviewedRab(object $test, User $pmReviewer): Quotation
{
    Carbon::setTestNow(Carbon::parse('2026-10-06 09:00:00'));
    $service = app(QuotationService::class);
    $quotation = Quotation::factory()->create(['created_by' => $test->estimator->id]);
    $service->replaceItems($quotation, collect(['Kitchen Set', 'Partisi', 'Plafon', 'Pengecatan'])
        ->map(fn (string $name) => ['description' => $name, 'qty' => 1, 'unit_id' => unitId('ls'), 'unit_price' => 1_000_000])
        ->all());

    $service->submit($quotation->fresh());
    reviewQuotation($quotation->fresh(), $pmReviewer, ['Partisi' => 'Ukuran salah']);

    $service->submit($quotation->fresh());
    reviewQuotation($quotation->fresh(), $pmReviewer);
    $service->sendToClient($quotation->fresh(), $test->marketing);
    $service->clientReject($quotation->fresh(), $test->marketing, 'Harga terlalu tinggi.');

    $service->submit($quotation->fresh());
    reviewQuotation($quotation->fresh(), $pmReviewer);
    $service->sendToClient($quotation->fresh(), $test->marketing);
    $service->clientApprove($quotation->fresh()->currentShareLink(), true, '127.0.0.1', 'Test');

    expect($quotation->fresh()->status)->toBe(QuotationStatus::ClientApproved)
        ->and($quotation->fresh()->version)->toBe(3);

    return $quotation;
}

test('first-pass rate only counts the PM review of version 1', function () {
    reviewedRab($this, $this->pm);

    // v1: 3 of 4 items ✔ — the later, all-✔ versions don't lift it.
    expect($this->registry->compute('estimator_first_pass_rate', $this->estimator, ...$this->october))->toBe(75.0)
        ->and($this->registry->compute('estimator_first_pass_rate', $this->estimator, ...$this->september))->toBeNull()
        ->and($this->registry->compute('estimator_first_pass_rate', kpiReviewUser('ESTIMATOR'), ...$this->october))->toBeNull();
});

test('returned count adds the PM returns; no review in the month is no data', function () {
    reviewedRab($this, $this->pm);

    expect($this->registry->compute('estimator_returned_count', $this->estimator, ...$this->october))->toBe(1.0)
        ->and($this->registry->compute('estimator_returned_count', $this->estimator, ...$this->september))->toBeNull();
});

test('a client rejection counts against the PM who approved that version', function () {
    reviewedRab($this, $this->pm);

    // The PM approved v2 (rejected by the client) and v3 (approved) → 1.
    expect($this->registry->compute('pm_review_escaped_count', $this->pm, ...$this->october))->toBe(1.0)
        ->and($this->registry->compute('pm_review_escaped_count', $this->pm, ...$this->september))->toBeNull()
        ->and($this->registry->compute('pm_review_escaped_count', kpiReviewUser('PM'), ...$this->october))->toBeNull();
});

test('the escaped count works for an Asisten PM reviewer too', function () {
    $assistant = kpiReviewUser('ASISTEN_PM');
    reviewedRab($this, $assistant);

    expect($this->registry->compute('pm_review_escaped_count', $assistant, ...$this->october))->toBe(1.0)
        ->and($this->registry->compute('pm_review_escaped_count', $this->pm, ...$this->october))->toBeNull();
});

test('legacy CEO returns (before Sprint 23) still count against the PM', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-06 09:00:00'));
    $quotation = Quotation::factory()->create(['created_by' => $this->estimator->id]);
    QuotationApproval::create(['quotation_id' => $quotation->id, 'version' => 1, 'approver_id' => $this->pm->id, 'approver_role' => 'PM', 'status' => 'APPROVED']);
    QuotationApproval::create(['quotation_id' => $quotation->id, 'version' => 1, 'approver_id' => kpiReviewUser('CEO')->id, 'approver_role' => 'CEO', 'status' => 'REJECTED', 'note' => 'Lama']);

    expect($this->registry->compute('pm_review_escaped_count', $this->pm, ...$this->october))->toBe(1.0)
        ->and($this->registry->compute('estimator_returned_count', $this->estimator, ...$this->october))->toBe(1.0);
});

test('a clean RAB scores 100 % first pass and 0 returns — a real 0, not "no data"', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-08 09:00:00'));
    $service = app(QuotationService::class);
    $quotation = Quotation::factory()->create(['created_by' => $this->estimator->id]);
    $service->replaceItems($quotation, [['description' => 'Meja', 'qty' => 1, 'unit_id' => unitId('ls'), 'unit_price' => 500_000]]);
    $service->submit($quotation->fresh());
    reviewQuotation($quotation->fresh(), $this->pm);
    $service->sendToClient($quotation->fresh(), $this->marketing);
    $service->clientApprove($quotation->fresh()->currentShareLink(), true, '127.0.0.1', 'Test');

    expect($this->registry->compute('estimator_first_pass_rate', $this->estimator, ...$this->october))->toBe(100.0)
        ->and($this->registry->compute('estimator_returned_count', $this->estimator, ...$this->october))->toBe(0.0)
        ->and($this->registry->compute('pm_review_escaped_count', $this->pm, ...$this->october))->toBe(0.0);
});

test('the metrics are offered to the right roles and sit in the default Estimator & PM templates', function () {
    $options = collect($this->registry->options())->keyBy('key');

    expect($options['estimator_first_pass_rate']['direction'])->toBe('HIGHER_BETTER')
        ->and($options['estimator_returned_count']['direction'])->toBe('LOWER_BETTER')
        ->and($options['pm_review_escaped_count']['roles'])->toBe(['PM', 'ASISTEN_PM']);

    $estimator = collect(KpiDemoSeeder::TEMPLATES['Estimator']);
    $pm = collect(KpiDemoSeeder::TEMPLATES['Project Manager']);
    expect($estimator->pluck(1)->filter()->values()->all())->toContain('estimator_first_pass_rate', 'estimator_returned_count')
        ->and($pm->pluck(1)->all())->toContain('pm_review_escaped_count')
        ->and($estimator->sum(fn (array $row) => $row[3]))->toBe(100)
        ->and($pm->sum(fn (array $row) => $row[3]))->toBe(100);
});
