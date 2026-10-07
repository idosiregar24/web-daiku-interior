<?php

use App\Enums\LeadSurveyStatus;
use App\Enums\QuotationStatus;
use App\Enums\QuotationType;
use App\Models\BankAccount;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\LeadSurvey;
use App\Models\Quotation;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\LeadService;
use App\Services\QuotationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 17 Sub 02 (T5, lead #37) — an outside-Pekanbaru survey and its RAB
 * Jasa Survey are paired whichever comes first, so verifying the invoice
 * always makes the survey SIAP; `daiku:relink-surveys` repairs old data.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'Asia/Jakarta'));
    $this->seed(RoleSeeder::class);
    $this->marketing = User::factory()->create();
    $this->marketing->assignRole('MARKETING');
    $this->finance = User::factory()->create();
    $this->finance->assignRole('FINANCE');
    $this->account = BankAccount::factory()->create(['is_active' => true]);
    $this->lead = Lead::factory()->create(['assigned_to' => $this->marketing->id]);
});

afterEach(fn () => Carbon::setTestNow());

function relinkSchedule(object $test): LeadSurvey
{
    return app(LeadService::class)->scheduleSurvey($test->lead, ['scheduled_at' => '2026-10-09 09:00:00', 'is_outside_pekanbaru' => true], $test->marketing);
}

function relinkRequestRab(object $test): Quotation
{
    $quotation = app(QuotationService::class)->request($test->lead, QuotationType::Survey, 'Survey luar kota', $test->marketing);
    $quotation->update(['status' => QuotationStatus::ClientApproved->value, 'total_amount' => 750_000]);

    return $quotation->fresh();
}

function relinkPay(object $test, Quotation $quotation, bool $verify = true): Invoice
{
    $invoices = app(InvoiceService::class);
    $invoice = $invoices->issueForQuotation($quotation, ['due_date' => '2026-10-08'], $test->marketing);
    $invoice = $invoices->submitProof($invoice, 'https://drive.google.com/bukti', $test->marketing);

    return $verify ? $invoices->verify($invoice, ['bank_account_id' => $test->account->id, 'paid_date' => '2026-10-05'], $test->finance) : $invoice;
}

test('survey first, then RAB, then payment → SIAP', function () {
    $survey = relinkSchedule($this);
    relinkPay($this, relinkRequestRab($this));

    expect($survey->fresh()->status)->toBe(LeadSurveyStatus::Siap);
});

test('RAB first, then survey, then payment → SIAP (lead #37 order)', function () {
    $quotation = relinkRequestRab($this);
    $survey = relinkSchedule($this);

    expect($survey->fresh()->quotation_id)->toBe($quotation->id)
        ->and($quotation->fresh()->lead_survey_id)->toBe($survey->id)
        ->and($survey->fresh()->status)->toBe(LeadSurveyStatus::MenungguBayar);

    relinkPay($this, $quotation);

    expect($survey->fresh()->status)->toBe(LeadSurveyStatus::Siap);
});

test('RAB already paid before the survey is scheduled → the survey is SIAP at once', function () {
    relinkPay($this, relinkRequestRab($this));

    $survey = relinkSchedule($this);

    expect($survey->fresh()->status)->toBe(LeadSurveyStatus::Siap);
});

test('a cancelled RAB is not linked; an inside-Pekanbaru survey never waits', function () {
    $quotation = relinkRequestRab($this);
    $quotation->update(['status' => QuotationStatus::Cancelled->value]);

    $outside = relinkSchedule($this);
    $inside = app(LeadService::class)->scheduleSurvey($this->lead, ['scheduled_at' => '2026-10-10 09:00:00', 'is_outside_pekanbaru' => false], $this->marketing);

    expect($outside->fresh()->quotation_id)->toBeNull()
        ->and($inside->fresh()->status)->toBe(LeadSurveyStatus::Dijadwalkan)
        ->and($inside->fresh()->quotation_id)->toBeNull();
});

test('relink command repairs a paid but unlinked survey; dry run changes nothing; re-running is a no-op', function () {
    relinkPay($this, relinkRequestRab($this));
    // The pre-fix state: the survey was created without being paired.
    $survey = LeadSurvey::factory()->outsidePekanbaru()->create(['lead_id' => $this->lead->id]);

    $this->artisan('daiku:relink-surveys --dry-run')->expectsOutputToContain('[dry-run] 1 lead diperbaiki.')->assertSuccessful();
    expect($survey->fresh()->quotation_id)->toBeNull()
        ->and($survey->fresh()->status)->toBe(LeadSurveyStatus::MenungguBayar);

    $this->artisan('daiku:relink-surveys')->expectsOutputToContain('→ SIAP')->assertSuccessful();
    expect($survey->fresh()->status)->toBe(LeadSurveyStatus::Siap)
        ->and($survey->fresh()->quotation_id)->not->toBeNull();

    $this->artisan('daiku:relink-surveys')->expectsOutputToContain('0 lead diperbaiki.')->assertSuccessful();
});

test('the lead page tells where a waiting survey payment stands', function () {
    $quotation = relinkRequestRab($this);
    relinkSchedule($this);
    $invoice = relinkPay($this, $quotation, verify: false);

    $this->actingAs($this->marketing)->get(route('crm.leads.show', $this->lead))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lead.surveys.0.quotation.id', $quotation->id)
            ->where('lead.surveys.0.quotation.invoices.0.number', $invoice->number)
            ->where('lead.surveys.0.quotation.invoices.0.status', 'MENUNGGU_VERIFIKASI'));
});
