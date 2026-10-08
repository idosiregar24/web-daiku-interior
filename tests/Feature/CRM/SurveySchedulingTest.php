<?php

use App\Enums\InvoiceStatus;
use App\Enums\LeadSurveyStatus;
use App\Enums\NotificationPriority;
use App\Enums\NotificationType;
use App\Enums\QuotationStatus;
use App\Enums\QuotationType;
use App\Models\BankAccount;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\LeadSurvey;
use App\Models\Notification;
use App\Models\Quotation;
use App\Models\User;
use App\Services\ActionInboxService;
use App\Services\InvoiceService;
use App\Services\LeadService;
use App\Services\QuotationService;
use App\Support\NotificationTarget;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 19 Sub 02 & 04 — a paid RAB Jasa Survey prompts Marketing to
 * schedule the survey (P1 + "Perlu Tindakan"), and the survey's schedule is
 * told to the CEO and every PM.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-08 10:00:00', 'Asia/Jakarta'));
    $this->seed(RoleSeeder::class);
    $this->marketing = surveyUser('MARKETING');
    $this->finance = surveyUser('FINANCE');
    $this->ceo = surveyUser('CEO');
    $this->pm = surveyUser('PM');
    $this->otherPm = surveyUser('PM');
    $this->assistant = surveyUser('ASISTEN_PM');
    $this->account = BankAccount::factory()->create(['is_active' => true]);
    $this->lead = Lead::factory()->create(['assigned_to' => $this->marketing->id, 'client_name' => 'Bu Sari', 'address' => 'Jl. Riau 1']);
});

afterEach(fn () => Carbon::setTestNow());

function surveyUser(string $role): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user;
}

/** A RAB Jasa Survey approved by the client, invoiced and with proof sent (status only — Sub 01 owns submitProof). */
function surveyRabAwaitingVerification(object $test): array
{
    $quotation = app(QuotationService::class)->request($test->lead, QuotationType::Survey, 'Survey Bangkinang', $test->marketing);
    $quotation->update(['status' => QuotationStatus::ClientApproved->value, 'total_amount' => 750_000]);
    $invoice = app(InvoiceService::class)->issueForQuotation($quotation, ['due_date' => '2026-10-10'], $test->marketing);
    $invoice->update(['status' => InvoiceStatus::MenungguVerifikasi->value]);

    return [$quotation->fresh(), $invoice->fresh()];
}

function verifySurveyInvoice(object $test, Invoice $invoice): void
{
    app(InvoiceService::class)->verify($invoice, ['bank_account_id' => $test->account->id, 'paid_date' => '2026-10-08'], $test->finance);
}

function surveyInbox(User $user, string $key): ?array
{
    return collect(app(ActionInboxService::class)->for($user, fresh: true))->firstWhere('key', $key);
}

// ── Sub 02 — payment verified ───────────────────────────────────────────

test('a verified payment now rings Marketing (P2) and says what comes next', function () {
    [, $invoice] = surveyRabAwaitingVerification($this);

    verifySurveyInvoice($this, $invoice);

    $verified = Notification::where('user_id', $this->marketing->id)->where('type', 'invoice_verified')->sole();
    expect(NotificationType::InvoiceVerified->priority())->toBe(NotificationPriority::ActionRequired)
        ->and($verified->priority)->toBe(NotificationPriority::ActionRequired->value)
        ->and($verified->message)->toContain('pastikan jadwalnya sudah dibuat');
});

test('a paid RAB Jasa Survey without a survey asks Marketing to schedule it, once', function () {
    [$quotation, $invoice] = surveyRabAwaitingVerification($this);

    verifySurveyInvoice($this, $invoice);

    $prompt = Notification::where('user_id', $this->marketing->id)->where('type', 'survey_to_schedule')->sole();
    expect($prompt->priority)->toBe(NotificationPriority::ClientWaiting->value)
        ->and(Notification::where('type', 'lead_survey_ready')->exists())->toBeFalse()
        ->and(NotificationTarget::for($prompt))->toBe(route('quotations.show', ['quotation' => $quotation->id, 'survey' => 'new']));

    $group = surveyInbox($this->marketing, 'survey-schedule');
    expect($group['count'])->toBe(1)
        ->and($group['items'][0]['href'])->toBe(route('quotations.show', ['quotation' => $quotation->id, 'survey' => 'new']));

    // Scheduling it from the RAB links it to the paid RAB (SIAP at once) and empties the queue.
    $survey = app(LeadService::class)->scheduleSurvey($this->lead, ['scheduled_at' => '2026-10-12 09:00', 'is_outside_pekanbaru' => true], $this->marketing);

    expect($survey->fresh()->status)->toBe(LeadSurveyStatus::Siap)
        ->and($survey->fresh()->quotation_id)->toBe($quotation->id)
        ->and(surveyInbox($this->marketing, 'survey-schedule'))->toBeNull();
});

test('a survey already waiting for payment gets the usual "siap berangkat" P1, not a second one', function () {
    LeadSurvey::factory()->outsidePekanbaru()->create(['lead_id' => $this->lead->id]);
    [, $invoice] = surveyRabAwaitingVerification($this);

    verifySurveyInvoice($this, $invoice);

    $p1 = Notification::where('user_id', $this->marketing->id)->get()
        ->filter(fn (Notification $row) => $row->priority === NotificationPriority::ClientWaiting->value)->pluck('type');
    expect($p1->values()->all())->toBe(['lead_survey_ready']);
});

test('a cancelled survey puts the paid RAB back in the queue; a finished one does not', function () {
    [$quotation, $invoice] = surveyRabAwaitingVerification($this);
    verifySurveyInvoice($this, $invoice);
    $survey = app(LeadService::class)->scheduleSurvey($this->lead, ['scheduled_at' => '2026-10-12 09:00', 'is_outside_pekanbaru' => true], $this->marketing);

    app(LeadService::class)->cancelSurvey($survey, 'Klien pindah tanggal', $this->marketing);
    expect(Quotation::query()->surveyToSchedule()->pluck('id')->all())->toBe([$quotation->id]);

    $second = app(LeadService::class)->scheduleSurvey($this->lead, ['scheduled_at' => '2026-10-14 09:00', 'is_outside_pekanbaru' => true], $this->marketing);
    app(LeadService::class)->completeSurvey($second->fresh(), ['result_note' => 'Ukur ruang tamu']);
    expect(Quotation::query()->surveyToSchedule()->exists())->toBeFalse();
});

// ── Sub 04 — the schedule goes to the CEO and every PM ──────────────────

test('scheduling a survey tells the CEO and every PM, not the Asisten PM or Marketing', function () {
    $this->actingAs($this->marketing)->post(route('crm.surveys.store', $this->lead), [
        'scheduled_at' => '2026-10-12 09:00',
    ])->assertSessionHasNoErrors();

    $rows = Notification::where('type', 'survey_scheduled')->get();
    expect($rows->pluck('user_id')->sort()->values()->all())->toBe(collect([$this->ceo->id, $this->pm->id, $this->otherPm->id])->sort()->values()->all())
        ->and($rows->first()->priority)->toBe(NotificationPriority::ActionRequired->value)
        ->and($rows->first()->message)->toContain('Bu Sari')->toContain('12 Okt 2026 09:00')->toContain('Jl. Riau 1')
        ->and(NotificationTarget::for($rows->first()))->toBe(route('crm.leads.show', $this->lead));
});

test('an outside-Pekanbaru survey is announced as waiting for payment, then as going ahead', function () {
    $survey = app(LeadService::class)->scheduleSurvey($this->lead, ['scheduled_at' => '2026-10-12 09:00', 'is_outside_pekanbaru' => true], $this->marketing);
    expect(Notification::where('user_id', $this->ceo->id)->where('type', 'survey_scheduled')->sole()->message)->toContain('menunggu pembayaran');

    [, $invoice] = surveyRabAwaitingVerification($this);
    verifySurveyInvoice($this, $invoice);

    expect($survey->fresh()->status)->toBe(LeadSurveyStatus::Siap)
        ->and(Notification::where('user_id', $this->pm->id)->where('type', 'survey_confirmed')->sole()->priority)->toBe(NotificationPriority::Update->value);
});

test('rescheduling rings only when time or place changes; cancelling gives the reason', function () {
    $survey = app(LeadService::class)->scheduleSurvey($this->lead, ['scheduled_at' => '2026-10-12 09:00'], $this->marketing);

    $this->actingAs($this->marketing)->put(route('crm.surveys.update', $survey), ['scheduled_at' => '2026-10-12 09:00'])->assertSessionHasNoErrors();
    expect(Notification::where('type', 'survey_rescheduled')->exists())->toBeFalse();

    $this->actingAs($this->marketing)->put(route('crm.surveys.update', $survey), ['scheduled_at' => '2026-10-13 14:00'])->assertSessionHasNoErrors();
    expect(Notification::where('user_id', $this->ceo->id)->where('type', 'survey_rescheduled')->sole()->message)
        ->toContain('13 Okt 2026 14:00')->toContain('sebelumnya')->toContain('12 Okt 2026 09:00');

    $this->actingAs($this->marketing)->post(route('crm.surveys.cancel', $survey), ['reason' => 'Klien sakit'])->assertSessionHasNoErrors();
    expect(Notification::where('user_id', $this->pm->id)->where('type', 'survey_cancelled')->sole()->message)->toContain('Klien sakit');
});

test('the CEO scheduling a survey is not told about it themselves', function () {
    $this->actingAs($this->ceo)->post(route('crm.surveys.store', $this->lead), ['scheduled_at' => '2026-10-12 09:00'])->assertSessionHasNoErrors();

    expect(Notification::where('type', 'survey_scheduled')->where('user_id', $this->ceo->id)->exists())->toBeFalse()
        ->and(Notification::where('type', 'survey_scheduled')->count())->toBe(2);
});

// ── Sub 03 — scheduling from the RAB page ───────────────────────────────

test('the RAB page offers "Jadwalkan Survey" to Marketing once the survey is paid', function () {
    [$quotation, $invoice] = surveyRabAwaitingVerification($this);

    $this->actingAs($this->marketing)->get(route('quotations.show', $quotation))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canOpenLead', true)
            ->where('surveyPanel.paid', false)
            ->where('surveyPanel.canSchedule', false));

    verifySurveyInvoice($this, $invoice);

    $this->actingAs($this->marketing)->get(route('quotations.show', $quotation))
        ->assertInertia(fn (Assert $page) => $page
            ->where('surveyPanel.paid', true)
            ->where('surveyPanel.canSchedule', true)
            ->where('surveyPanel.survey', null)
            ->where('surveyPanel.leadAddress', 'Jl. Riau 1'));

    $this->actingAs($this->marketing)->post(route('crm.surveys.store', $this->lead), [
        'scheduled_at' => '2026-10-12 09:00',
        'is_outside_pekanbaru' => true,
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->marketing)->get(route('quotations.show', $quotation))
        ->assertInertia(fn (Assert $page) => $page
            ->where('surveyPanel.canSchedule', false)
            ->where('surveyPanel.canReschedule', true)
            ->where('surveyPanel.survey.status', LeadSurveyStatus::Siap->value));
});

test('only the survey writers get the survey panel; others still get the way back to the lead', function (string $role, bool $panel, bool $lead) {
    [$quotation] = surveyRabAwaitingVerification($this);

    $this->actingAs(surveyUser($role))->get(route('quotations.show', $quotation))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canOpenLead', $lead)
            ->where('surveyPanel', fn ($value) => $panel ? $value !== null : $value === null));
})->with([
    'CEO' => ['CEO', true, true],
    'Estimator' => ['ESTIMATOR', false, true],
    'PM' => ['PM', false, true],
    'Finance' => ['FINANCE', false, false],
]);

test('a non-survey RAB has no survey panel', function () {
    $quotation = Quotation::factory()->create(['lead_id' => $this->lead->id, 'type' => QuotationType::Desain->value, 'status' => QuotationStatus::ClientApproved->value]);

    $this->actingAs($this->marketing)->get(route('quotations.show', $quotation))
        ->assertInertia(fn (Assert $page) => $page->where('surveyPanel', null)->where('canOpenLead', true));
});
