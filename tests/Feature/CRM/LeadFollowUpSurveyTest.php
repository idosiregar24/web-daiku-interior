<?php

use App\Enums\LeadStatus;
use App\Enums\LeadSurveyStatus;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadFollowUp;
use App\Models\LeadSource;
use App\Models\LeadSurvey;
use App\Models\PipelineLog;
use App\Models\User;
use App\Services\LeadService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->marketing = crmUser('MARKETING');
    $this->lead = Lead::factory()->create(['assigned_to' => $this->marketing->id, 'address' => 'Jl. Tegal Sari 12', 'maps_url' => 'https://maps.google.com/?q=tegal+sari']);
});

function crmUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

// ── Lead: tanggal pertama dihubungi, alamat, Maps ────────────────────────

test('a new lead keeps first-contact date, address, Maps link, and its first follow-up as FU-1', function () {
    $this->actingAs($this->marketing)->post(route('crm.leads.store'), [
        'client_name' => 'Budi',
        'contact' => '0812',
        'lead_source_id' => LeadSource::findOrCreateByName('Instagram')->id,
        'priority' => 'WARM',
        'assigned_to' => $this->marketing->id,
        'first_contacted_at' => now()->subDay()->toDateString(),
        'address' => 'Jl. Sudirman 1, Pekanbaru',
        'maps_url' => 'https://maps.app.goo.gl/abc',
        'follow_up_date' => now()->addDays(2)->toDateString(),
    ])->assertSessionHasNoErrors();

    $lead = Lead::where('client_name', 'Budi')->sole();
    expect($lead->first_contacted_at->toDateString())->toBe(now()->subDay()->toDateString())
        ->and($lead->maps_url)->toBe('https://maps.app.goo.gl/abc')
        ->and($lead->followUps()->sole()->only(['sequence', 'done_at']))->toBe(['sequence' => 1, 'done_at' => null]);
});

test('a Maps link that is not http/https is refused', function (string $url) {
    $this->actingAs($this->marketing)->put(route('crm.leads.update', $this->lead), [
        ...$this->lead->only(['client_name', 'contact', 'lead_source_id', 'assigned_to']),
        'priority' => 'WARM',
        'maps_url' => $url,
    ])->assertSessionHasErrors(['maps_url' => 'Link Google Maps harus berupa URL http/https.']);

    $this->actingAs($this->marketing)->post(route('crm.surveys.store', $this->lead), [
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        'maps_url' => $url,
    ])->assertSessionHasErrors('maps_url');
})->with(['javascript:alert(1)', 'ftp://maps.example.com', 'bukan url']);

// ── Follow-up bertingkat ─────────────────────────────────────────────────

test('follow-ups are numbered in order and each is done with a result note', function () {
    foreach ([1, 2, 3] as $day) {
        $this->actingAs($this->marketing)->post(route('crm.follow-ups.store', $this->lead), [
            'scheduled_date' => now()->addDays($day)->toDateString(),
        ])->assertSessionHasNoErrors();
    }

    expect($this->lead->followUps()->pluck('sequence')->all())->toBe([1, 2, 3]);

    $first = $this->lead->followUps()->first();
    $this->actingAs($this->marketing)->post(route('crm.follow-ups.complete', $first), [])
        ->assertSessionHasErrors(['result_note' => 'Catatan hasil follow-up wajib diisi.']);
    $this->actingAs($this->marketing)->post(route('crm.follow-ups.complete', $first), ['result_note' => 'Klien minta penawaran'])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->marketing)->post(route('crm.follow-ups.complete', $first), ['result_note' => 'Lagi'])
        ->assertSessionHasErrors('result_note');

    expect($first->fresh()->done_at)->not->toBeNull()->and($first->fresh()->result_note)->toBe('Klien minta penawaran');
});

test('the lead page carries the timeline and suggests Lost from FU-5 on, without blocking', function () {
    foreach (range(1, 5) as $i) {
        app(LeadService::class)->addFollowUp($this->lead, ['scheduled_date' => now()->toDateString()], $this->marketing);
    }

    $this->actingAs($this->marketing)->get(route('crm.leads.show', $this->lead))
        ->assertInertia(fn (Assert $page) => $page
            ->where('suggestLostFrom', LeadFollowUp::SUGGEST_LOST_FROM)
            ->has('lead.follow_ups', 5)
            ->where('lead.follow_ups.4.sequence', 5));

    expect(LeadFollowUp::SUGGEST_LOST_FROM)->toBe(5)
        ->and(app(LeadService::class)->addFollowUp($this->lead, ['scheduled_date' => now()->toDateString()], $this->marketing)->sequence)->toBe(6);
});

test('a LOST lead takes no more follow-ups or surveys', function () {
    $this->lead->update(['status' => LeadStatus::Lost->value, 'lost_reason' => 'x']);

    $this->actingAs($this->marketing)->post(route('crm.follow-ups.store', $this->lead), ['scheduled_date' => now()->toDateString()])
        ->assertSessionHasErrors('scheduled_date');
    $this->actingAs($this->marketing)->post(route('crm.surveys.store', $this->lead), ['scheduled_at' => now()->addDay()->toDateTimeString()])
        ->assertSessionHasErrors('scheduled_at');
});

test('the next open follow-up drives the overdue scope, the list column and the reminder', function () {
    LeadFollowUp::factory()->done()->create(['lead_id' => $this->lead->id, 'scheduled_date' => now()->subDays(5)->toDateString()]);
    LeadFollowUp::factory()->create(['lead_id' => $this->lead->id, 'scheduled_date' => now()->subDay()->toDateString()]);
    LeadFollowUp::factory()->create(['lead_id' => $this->lead->id, 'scheduled_date' => now()->addDays(4)->toDateString()]);

    expect(Lead::overdueFollowUp()->pluck('id')->all())->toBe([$this->lead->id])
        ->and(Lead::withNextFollowUp()->find($this->lead->id)->next_follow_up_date)->toBe(now()->subDay()->toDateString())
        ->and(app(LeadService::class)->sendFollowUpReminders())->toBe(1);

    $this->actingAs($this->marketing)->get(route('crm.leads.index'))
        ->assertInertia(fn (Assert $page) => $page->where('leads.data.0.follow_ups_count', 3));
});

// ── Survey ───────────────────────────────────────────────────────────────

test('a survey in Pekanbaru is scheduled, takes the lead address by default, and can be finished', function () {
    $this->actingAs($this->marketing)->post(route('crm.surveys.store', $this->lead), [
        'scheduled_at' => now()->addDay()->toDateTimeString(),
    ])->assertSessionHasNoErrors();

    $survey = LeadSurvey::sole();
    expect($survey->status)->toBe(LeadSurveyStatus::Dijadwalkan)
        ->and($survey->address)->toBe('Jl. Tegal Sari 12')
        ->and($survey->maps_url)->toBe('https://maps.google.com/?q=tegal+sari');

    $this->actingAs($this->marketing)->post(route('crm.surveys.complete', $survey), ['result_note' => 'Ukur ruang tamu 4×5 m'])
        ->assertSessionHasNoErrors();

    expect($survey->fresh()->status)->toBe(LeadSurveyStatus::Selesai);
});

test('a survey outside Pekanbaru waits for payment and only becomes ready through Finance verification', function () {
    $service = app(LeadService::class);
    $survey = $service->scheduleSurvey($this->lead, ['scheduled_at' => now()->addDay()->toDateTimeString(), 'is_outside_pekanbaru' => true], $this->marketing);

    expect($survey->status)->toBe(LeadSurveyStatus::MenungguBayar);

    $this->actingAs($this->marketing)->post(route('crm.surveys.complete', $survey), ['result_note' => 'x'])
        ->assertSessionHasErrors(['result_note' => 'Survey luar Pekanbaru belum bisa diselesaikan — pembayaran RAB Jasa Survey belum diverifikasi Finance.']);

    $service->markSurveyReady($survey);
    expect($survey->fresh()->status)->toBe(LeadSurveyStatus::Siap);

    $service->completeSurvey($survey->fresh(), ['result_note' => 'Survey selesai']);
    expect($survey->fresh()->status)->toBe(LeadSurveyStatus::Selesai);
});

test('surveys can repeat, be rescheduled, and be cancelled with an audited reason', function () {
    $service = app(LeadService::class);
    $first = $service->scheduleSurvey($this->lead, ['scheduled_at' => now()->addDay()->toDateTimeString()], $this->marketing);
    $second = $service->scheduleSurvey($this->lead, ['scheduled_at' => now()->addDays(2)->toDateTimeString()], $this->marketing);

    expect($second->sequence)->toBe(2);

    $this->actingAs($this->marketing)->put(route('crm.surveys.update', $first), [
        'scheduled_at' => now()->addDays(3)->setTime(9, 0)->toDateTimeString(),
        'is_outside_pekanbaru' => true,
    ])->assertSessionHasErrors('is_outside_pekanbaru');

    $this->actingAs($this->marketing)->post(route('crm.surveys.cancel', $first), [])->assertSessionHasErrors('reason');
    $this->actingAs($this->marketing)->post(route('crm.surveys.cancel', $first), ['reason' => 'Klien ke luar kota'])->assertSessionHasNoErrors();

    expect($first->fresh()->status)->toBe(LeadSurveyStatus::Batal)
        ->and(AuditLog::where('action', 'crm.survey_cancelled')->sole()->new_values['reason'])->toBe('Klien ke luar kota')
        ->and(fn () => $service->updateSurvey($first->fresh(), ['scheduled_at' => now()->toDateTimeString()]))->toThrow(ValidationException::class);
});

// ── Ajukan Desain/Survey ─────────────────────────────────────────────────

test('"Ajukan Desain/Survey" moves the lead to DEAL_DESAIN, scheduling the survey when chosen', function (string $type) {
    $this->actingAs($this->marketing)->post(route('crm.leads.submitRequest', $this->lead), [
        'type' => $type,
        'note' => 'Desain ruang tamu minimalis.',
        'scheduled_at' => $type === 'SURVEY' ? now()->addDay()->toDateTimeString() : null,
    ])->assertSessionHasNoErrors();

    expect($this->lead->fresh()->status)->toBe(LeadStatus::DealDesain)
        ->and(LeadSurvey::count())->toBe($type === 'SURVEY' ? 1 : 0)
        ->and(PipelineLog::where('lead_id', $this->lead->id)->where('to_status', 'DEAL_DESAIN')->exists())->toBeTrue();
})->with(['SURVEY', 'RAB_DESAIN']);

test('a survey request needs a schedule', function () {
    $this->actingAs($this->marketing)->post(route('crm.leads.submitRequest', $this->lead), ['type' => 'SURVEY'])
        ->assertSessionHasErrors(['scheduled_at' => 'Jadwal survey wajib diisi.']);
});

// ── RBAC ─────────────────────────────────────────────────────────────────

test('only Marketing and CEO write follow-ups and surveys; other lead readers get 403', function (string $role, int $status) {
    $user = crmUser($role);
    $survey = LeadSurvey::factory()->create(['lead_id' => $this->lead->id]);
    $followUp = LeadFollowUp::factory()->create(['lead_id' => $this->lead->id]);

    $this->actingAs($user)->post(route('crm.follow-ups.store', $this->lead), ['scheduled_date' => now()->toDateString()])->assertStatus($status);
    $this->actingAs($user)->post(route('crm.follow-ups.complete', $followUp), ['result_note' => 'ok'])->assertStatus($status);
    $this->actingAs($user)->post(route('crm.surveys.complete', $survey), ['result_note' => 'ok'])->assertStatus($status);
    $this->actingAs($user)->post(route('crm.leads.submitRequest', $this->lead), ['type' => 'RAB_DESAIN', 'note' => 'Desain ruang tamu minimalis.'])->assertStatus($status);
})->with([['MARKETING', 302], ['CEO', 302], ['DESIGNER', 403], ['PM', 403], ['ESTIMATOR', 403]]);

// ── Migrasi ──────────────────────────────────────────────────────────────

test('the Sprint 12 follow-up migration turns follow_up_date into FU-1 and back', function () {
    $migration = require database_path('migrations/2026_10_04_222218_create_lead_follow_ups_table.php');
    $migration->down();

    DB::table('leads')->where('id', $this->lead->id)->update(['follow_up_date' => '2026-11-01']);

    $migration->up();

    expect(Schema::hasColumn('leads', 'follow_up_date'))->toBeFalse()
        ->and(LeadFollowUp::sole()->only(['lead_id', 'sequence']))->toBe(['lead_id' => $this->lead->id, 'sequence' => 1])
        ->and(LeadFollowUp::sole()->scheduled_date->toDateString())->toBe('2026-11-01');

    $migration->down();
    expect(DB::table('leads')->where('id', $this->lead->id)->value('follow_up_date'))->toStartWith('2026-11-01');
    $migration->up();
});
