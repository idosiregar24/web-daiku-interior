<?php

use App\Enums\ProjectPhase;
use App\Models\Notification;
use App\Models\Project;
use App\Models\TechnicalMeeting;
use App\Models\User;
use App\Services\TechnicalMeetingService;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

/*
 * Sprint 34 — two ways into the TM: "Jadwalkan TM" (a date + time and the
 * people invited — they get an invitation and reminders H-1 and 2 hours
 * before) or "Langsung TM" (held now — the form only, nobody notified).
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00', 'Asia/Jakarta'));
    $this->seed(RoleSeeder::class);

    foreach (['pm' => 'PM', 'qa' => 'QA', 'logistics' => 'LOGISTICS', 'finance' => 'FINANCE'] as $key => $role) {
        $this->{$key} = User::factory()->create();
        $this->{$key}->assignRole($role);
    }

    $this->project = Project::factory()->create(['pm_id' => $this->pm->id, 'phase' => ProjectPhase::Tm->value, 'start_date' => '2026-10-12', 'end_date' => '2026-10-31']);
    $this->project->supervisors()->attach($this->qa->id);
    $this->save = fn (array $data, ?User $as = null) => $this->actingAs($as ?? $this->pm)
        ->put(route('projects.technical-meeting.save', $this->project), [...$data, 'fee_total' => '', 'dp_percent' => 10]);
});

afterEach(fn () => Carbon::setTestNow());

function tmNotified(User $user, string $type): int
{
    return Notification::where('user_id', $user->id)->where('type', $type)->count();
}

test('Jadwalkan TM needs a time and at least one invited person with an account', function () {
    ($this->save)(['mode' => 'JADWAL', 'meeting_date' => '2026-10-20', 'attendees' => [['user_id' => $this->logistics->id]]])
        ->assertSessionHasErrors('meeting_time');
    ($this->save)(['mode' => 'JADWAL', 'meeting_date' => '2026-10-20', 'meeting_time' => '10:00', 'attendees' => [['name' => 'Pak Darto']]])
        ->assertSessionHasErrors('attendees');

    ($this->save)(['mode' => 'JADWAL', 'meeting_date' => '2026-10-20', 'meeting_time' => '10:00', 'attendees' => [['user_id' => $this->logistics->id], ['name' => 'Pak Darto']]])
        ->assertSessionHasNoErrors();

    $meeting = TechnicalMeeting::sole();
    expect($meeting->mode)->toBe('JADWAL')
        ->and($meeting->scheduleLabel())->toBe('Selasa, 20 Oktober 2026 pukul 10.00')
        ->and($meeting->invited_at)->not->toBeNull()
        // The invited person and the Pengawas; the PM saved it himself.
        ->and(tmNotified($this->logistics, 'technical_meeting_scheduled'))->toBe(1)
        ->and(tmNotified($this->qa, 'technical_meeting_scheduled'))->toBe(1)
        ->and(tmNotified($this->pm, 'technical_meeting_scheduled'))->toBe(0)
        ->and(Notification::where('type', 'technical_meeting_scheduled')->where('user_id', $this->logistics->id)->value('message'))->toContain('pukul 10.00');
});

test('a person added later gets the invitation; the others are not invited again; a new time tells everyone', function () {
    $base = ['mode' => 'JADWAL', 'meeting_date' => '2026-10-20', 'meeting_time' => '10:00'];
    ($this->save)([...$base, 'attendees' => [['user_id' => $this->logistics->id]]])->assertSessionHasNoErrors();
    ($this->save)([...$base, 'attendees' => [['user_id' => $this->logistics->id], ['user_id' => $this->finance->id]]])->assertSessionHasNoErrors();

    expect(tmNotified($this->finance, 'technical_meeting_scheduled'))->toBe(1)
        ->and(tmNotified($this->logistics, 'technical_meeting_scheduled'))->toBe(1)
        ->and(tmNotified($this->qa, 'technical_meeting_scheduled'))->toBe(1);

    ($this->save)([...$base, 'meeting_time' => '13:30', 'attendees' => [['user_id' => $this->logistics->id], ['user_id' => $this->finance->id]]])->assertSessionHasNoErrors();
    expect(tmNotified($this->finance, 'technical_meeting_scheduled'))->toBe(2)
        ->and(Notification::where('user_id', $this->qa->id)->latest('id')->value('title'))->toBe('Jadwal TM Diubah');
});

test('Langsung TM is the form only — nobody is invited or reminded', function () {
    ($this->save)(['mode' => 'LANGSUNG', 'meeting_date' => '2026-10-14', 'notes' => 'Urutan kerja disepakati.', 'attendees' => [['user_id' => $this->logistics->id]]])
        ->assertSessionHasNoErrors();

    expect(TechnicalMeeting::sole()->mode)->toBe('LANGSUNG')
        ->and(Notification::where('type', 'technical_meeting_scheduled')->count())->toBe(0);

    Carbon::setTestNow(Carbon::parse('2026-10-14 08:30:00', 'Asia/Jakarta'));
    expect(app(TechnicalMeetingService::class)->sendReminders())->toBe(0);
});

test('reminders: H-1 and 2 hours before, each once, to the invited people, the Pengawas and the PM', function () {
    ($this->save)(['mode' => 'JADWAL', 'meeting_date' => '2026-10-20', 'meeting_time' => '10:00', 'attendees' => [['user_id' => $this->logistics->id]]])
        ->assertSessionHasNoErrors();
    $service = app(TechnicalMeetingService::class);

    Carbon::setTestNow(Carbon::parse('2026-10-19 09:00:00', 'Asia/Jakarta'));
    expect($service->sendReminders())->toBe(0);

    Carbon::setTestNow(Carbon::parse('2026-10-19 10:00:00', 'Asia/Jakarta'));
    expect($service->sendReminders())->toBe(1)
        ->and($service->sendReminders())->toBe(0)
        ->and(Notification::where('user_id', $this->logistics->id)->where('type', 'technical_meeting_reminder')->value('message'))->toContain('besok, Selasa, 20 Oktober 2026 pukul 10.00');

    Carbon::setTestNow(Carbon::parse('2026-10-20 08:15:00', 'Asia/Jakarta'));
    expect($service->sendReminders())->toBe(1)
        ->and(Notification::where('user_id', $this->pm->id)->where('type', 'technical_meeting_reminder')->latest('id')->value('message'))->toContain('hari ini pukul 10.00');

    expect(tmNotified($this->logistics, 'technical_meeting_reminder'))->toBe(2)
        ->and(tmNotified($this->qa, 'technical_meeting_reminder'))->toBe(2)
        ->and(tmNotified($this->pm, 'technical_meeting_reminder'))->toBe(2);

    // Done or past: nothing more.
    Carbon::setTestNow(Carbon::parse('2026-10-20 11:00:00', 'Asia/Jakarta'));
    expect($service->sendReminders())->toBe(0);
});

test('a TM set up for this afternoon only gets the 2-hours reminder; moving it counts the reminders again', function () {
    ($this->save)(['mode' => 'JADWAL', 'meeting_date' => '2026-10-14', 'meeting_time' => '15:00', 'attendees' => [['user_id' => $this->logistics->id]]])
        ->assertSessionHasNoErrors();
    $service = app(TechnicalMeetingService::class);

    Carbon::setTestNow(Carbon::parse('2026-10-14 13:00:00', 'Asia/Jakarta'));
    expect($service->sendReminders())->toBe(1)
        ->and(Notification::where('user_id', $this->logistics->id)->where('type', 'technical_meeting_reminder')->value('title'))->toBe('TM Sebentar Lagi');

    ($this->save)(['mode' => 'JADWAL', 'meeting_date' => '2026-10-16', 'meeting_time' => '15:00', 'attendees' => [['user_id' => $this->logistics->id]]])
        ->assertSessionHasNoErrors();
    expect(TechnicalMeeting::sole()->reminded_soon_at)->toBeNull();
});

test('the reminders run every 15 minutes', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->description ?? '', 'TechnicalMeetingReminderJob'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('*/15 * * * *');
});
